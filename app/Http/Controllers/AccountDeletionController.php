<?php

namespace App\Http\Controllers;

use App\Mail\AccountDeletionCodeMail;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;

/**
 * Account verwijderen door de gebruiker zelf.
 *
 * Apple vereist dit sinds 2022 voor elke app waarin je een account kunt
 * aanmaken: kan de gebruiker zich registreren, dan moet hij zich ín de app ook
 * kunnen laten verwijderen — een mailtje naar support volstaat niet.
 *
 * De meeste gegevens verdwijnen vanzelf: de user_id-koppelingen in de databank
 * staan op ON DELETE CASCADE, dus kaarten, missies, waypoints, activiteiten en
 * chatberichten gaan mee met de user-rij. Wat hier expliciet gebeurt is alles
 * wat de databank níét kan opruimen: het lopende Stripe-abonnement opzeggen en
 * de sessietokens intrekken.
 */
class AccountDeletionController extends Controller
{
    /** Geldigheid van de e-mailcode. */
    private const CODE_MINUTEN = 15;

    /** Bedenktijd voordat er echt iets verdwijnt. */
    private const BEDENKTIJD_DAGEN = 90;

    /**
     * POST /api/v1/user/account/deletion/request-code
     *
     * Stuurt een eenmalige code naar het e-mailadres van het account. Bewijst
     * dat de aanvrager ook bij de mailbox kan — een gekaapte sessie alleen is
     * niet genoeg om een account te laten verdwijnen.
     */
    public function requestCode(Request $request)
    {
        $user = $request->user();

        // Zes cijfers: lang genoeg om niet te raden binnen 15 minuten, kort
        // genoeg om over te typen vanaf een tweede apparaat.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'deletion_code_hash'       => Hash::make($code),
            'deletion_code_expires_at' => now()->addMinutes(self::CODE_MINUTEN),
        ])->save();

        try {
            Mail::to($user->email)->send(new AccountDeletionCodeMail($code, self::CODE_MINUTEN));
        } catch (\Throwable $e) {
            Log::error('[account] verwijdercode mailen mislukt', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'We konden de code niet versturen. Probeer het zo nog eens.'], 500);
        }

        return response()->json(['sent' => true, 'expires_in_minutes' => self::CODE_MINUTEN]);
    }

    /**
     * POST /api/v1/user/account/deletion
     *
     * Drie bewijzen tegelijk: de code uit de mail, het wachtwoord en het
     * overgetypte bevestigingswoord. Daarna wordt de verwijdering ingepland —
     * niet uitgevoerd. De gebruiker houdt 90 dagen om terug te
     * komen; inloggen annuleert het verzoek.
     */
    public function schedule(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'code'         => ['required', 'string'],
            'password'     => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ]);

        if (! $user->deletion_code_hash || ! $user->deletion_code_expires_at || now()->greaterThan($user->deletion_code_expires_at)) {
            return response()->json(['message' => 'De code is verlopen. Vraag een nieuwe aan.'], 422);
        }
        if (! Hash::check(trim($request->input('code')), $user->deletion_code_hash)) {
            return response()->json(['message' => 'De code klopt niet.'], 422);
        }
        if (! Hash::check($request->input('password'), (string) $user->password)) {
            return response()->json(['message' => 'Wachtwoord klopt niet.'], 422);
        }
        // De app stuurt het woord al in hoofdletters; hier nog eens normaliseren
        // zodat toetsenbordinstellingen geen roet in het eten gooien.
        $bevestiging = mb_strtoupper(trim((string) $request->input('confirmation')));
        // Moet exact overeenkomen met CONFIRM_WORD in DeleteAccountDialog.vue.
        if (! in_array($bevestiging, ['VERWIJDEREN', 'DELETE'], true)) {
            return response()->json(['message' => 'Typ het bevestigingswoord precies over.'], 422);
        }

        if ($blokkade = $this->teamBlokkade($user)) {
            return response()->json(['message' => $blokkade], 409);
        }

        // Abonnement meteen opzeggen: doorbetalen tijdens de bedenktijd voor
        // een account dat je wilt opheffen is niet uit te leggen.
        $this->cancelStripe($user);

        $purge = now()->addDays(self::BEDENKTIJD_DAGEN);
        $user->forceFill([
            'deletion_requested_at'    => now(),
            'deletion_purge_at'        => $purge,
            'deletion_code_hash'       => null,
            'deletion_code_expires_at' => null,
        ])->save();

        // Alle andere sessies intrekken; het huidige apparaat mag de melding
        // nog afmaken en logt daarna zelf uit.
        try {
            $huidig = $user->currentAccessToken();
            $user->tokens()->when($huidig, fn ($q) => $q->where('id', '!=', $huidig->id))->delete();
        } catch (\Throwable $e) {
            /* niet kritiek */
        }

        Log::info('[account] verwijdering ingepland', ['user_id' => $user->id, 'purge_at' => $purge->toIso8601String()]);

        return response()->json([
            'scheduled'         => true,
            'scheduled_purge_at' => $purge->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/user/account/deletion/cancel — binnen de bedenktijd.
     */
    public function cancel(Request $request)
    {
        $user = $request->user();
        $user->forceFill([
            'deletion_requested_at' => null,
            'deletion_purge_at'     => null,
        ])->save();

        return response()->json(['cancelled' => true]);
    }

    /**
     * Een teameigenaar met achterblijvende leden mag niet zomaar weg. Geen
     * eigen team of een team van één? Dan is er niets om over te dragen.
     */
    private function teamBlokkade($user): ?string
    {
        try {
            // User::teams() = de teams waarvan deze gebruiker EIGENAAR is
            // (hasMany op owner_id). Zitten daar nog andere leden in, dan zou
            // het team met de eigenaar mee verdwijnen.
            foreach ($user->teams as $team) {
                $anderen = $team->members()
                    ->where('user_id', '!=', $user->id)
                    ->count();
                if ($anderen > 0) {
                    return 'Je beheert een team met ' . $anderen . ' ' . ($anderen === 1 ? 'ander lid' : 'andere leden')
                        . '. Draag het eigenaarschap eerst over of verwijder de leden, daarna kun je je account verwijderen.';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[account] teamcontrole mislukt', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Lopend abonnement bij Stripe opzeggen. Best-effort en bewust vóór de
     * verwijdering: blijft het staan, dan blijft de klant betalen voor een
     * account dat niet meer bestaat. Lukt het niet, dan loggen we het zodat
     * het handmatig rechtgezet kan worden — het mag de verwijdering niet
     * tegenhouden, want dan zit de gebruiker vast aan een account dat hij weg
     * wil hebben.
     */
    private function cancelStripe($user): void
    {
        $secret = config('billing.stripe_secret');
        if (! $secret) {
            return;
        }

        $ids = Subscription::where('user_id', $user->id)
            ->whereNotNull('stripe_id')
            ->where('stripe_status', '!=', 'canceled')
            ->pluck('stripe_id');

        if ($ids->isEmpty()) {
            return;
        }

        $stripe = new StripeClient(['api_key' => $secret]);
        foreach ($ids as $stripeId) {
            try {
                $stripe->subscriptions->cancel($stripeId, []);
            } catch (\Throwable $e) {
                Log::warning('[account] Stripe-abonnement opzeggen mislukt', [
                    'user_id'       => $user->id,
                    'subscription'  => $stripeId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
