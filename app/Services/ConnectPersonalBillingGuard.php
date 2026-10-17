<?php
namespace App\Services;

use App\Models\ConnectMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Connect: door een organisatie beheerde accounts komen nooit in de
 * persoonlijke Stripe-omgeving (checkout, klantportaal, plan wijzigen,
 * opzeggen/hervatten, facturen, AppSumo). Abonnement en accountwijzigingen
 * regelt de beheerder van de organisatie; licenties lopen via ConnectBilling
 * (/connect/organizations/{org}/billing/*) en vallen hier dus buiten.
 *
 * "Beheerd" volgt ConnectManagement::context(): apparaataccounts
 * (@connect.milmap.nl) en actieve organisatieleden, ongeacht hun rol. Een
 * vertrokken lid (status removed) is weer een gewoon persoonlijk account. Het
 * e-maildomein telt hier alleen beperkend mee (fail-closed), nooit als recht.
 *
 * EnforceConnectManagement weigert billing/* al voor ingelogde beheerde
 * accounts; deze klasse is de tweede laag op de controller én dekt de publieke
 * routes waar alleen een e-mailadres binnenkomt (gast-checkout, AppSumo).
 */
class ConnectPersonalBillingGuard
{
    public const DEVICE_DOMAIN = 'connect.milmap.nl';

    public function __construct(private ConnectManagement $management) {}

    public static function isDeviceEmail(?string $email): bool
    {
        return (bool) preg_match('/@'.preg_quote(self::DEVICE_DOMAIN, '/').'$/i', trim((string) $email));
    }

    public function blocksUser(?User $user): bool
    {
        if (! $user) return false;
        if ($user->connect_device_account || self::isDeviceEmail($user->email)) return true;
        return (bool) ($this->management->context($user)['managed'] ?? false);
    }

    /** Publieke flows kennen alleen een e-mailadres: blokkeer apparaatdomein en beheerde accounts. */
    public function blocksEmail(?string $email): bool
    {
        if (self::isDeviceEmail($email)) return true;
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') return false;
        return $this->blocksUser(User::whereRaw('LOWER(email) = ?', [$email])->first());
    }

    /**
     * Gaat dit e-mailadres onder een organisatie vallen (apparaatdomein, of een
     * openstaande/actieve Connect-uitnodiging)? Dan maakt registratie géén
     * persoonlijke Stripe-customer aan.
     */
    public function joiningOrganisation(?string $email): bool
    {
        if (self::isDeviceEmail($email)) return true;
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! Schema::hasTable('connect_members')) return false;
        return ConnectMember::whereRaw('LOWER(email) = ?', [$email])->whereIn('status', ['pending', 'active'])->exists();
    }

    /** Zelfde vorm als de connect_policy_denied-weigering van EnforceConnectManagement. */
    public function deny(): JsonResponse
    {
        return response()->json([
            'code' => 'connect_policy_denied',
            'permission' => 'personal_billing',
            'message' => 'Abonnementen en accountwijzigingen worden geregeld door de beheerder van je organisatie. Neem contact op met je beheerder.',
        ], 403);
    }
}
