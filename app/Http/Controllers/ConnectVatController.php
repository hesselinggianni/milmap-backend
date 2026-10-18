<?php

namespace App\Http\Controllers;

use App\Models\ConnectOrganization;
use App\Services\{ConnectBilling, ConnectVat};
use Illuminate\Http\Request;

/**
 * Btw-behandeling voor de Connect-checkout: welke btw geldt voor dit land (en dit btw-nummer, gecontroleerd bij de EU via VIES)
 * en welke gegevens daarvoor nodig zijn. Alleen voor beheerders van de organisatie; dezelfde regels gelden bij het bestellen.
 */
class ConnectVatController extends Controller
{
    public function check(Request $r, ConnectOrganization $organization, ConnectBilling $billing)
    {
        abort_unless($organization->members()->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->exists(), 403);
        $data = $r->validate(['country' => 'required|string|size:2|regex:/^[A-Za-z]{2}$/', 'vat_id' => 'nullable|string|max:32']);
        $country = strtoupper($data['country']);
        $t = $billing->treatmentFor($country, $data['vat_id'] ?? null);
        $message = match ($t['reason']) {
            'format' => 'Een btw-nummer uit dit land begint met '.ConnectVat::prefix($country).'.',
            'invalid' => 'Dit btw-nummer is bij de EU niet als geldig bekend (VIES).',
            'unavailable' => 'De EU-controle (VIES) is nu niet bereikbaar. Probeer het zo opnieuw.',
            default => null,
        };
        return response()->json([
            'country' => $country, 'eu' => ConnectVat::isEu($country), 'prefix' => ConnectVat::isEu($country) ? ConnectVat::prefix($country) : null,
            'treatment' => $t['treatment'], 'percent' => $t['percent'], 'label' => $t['label'],
            'vat_id' => $t['vat_id'], 'valid' => $t['valid'], 'name' => $t['name'], 'reason' => $t['reason'], 'message' => $message,
            // Zonder dit nummer rekent MilMap 21% Nederlandse btw; mét een geldig nummer wordt de btw verlegd.
            'needs_vat_id_for_reverse_charge' => ConnectVat::isEu($country) && $country !== 'NL',
            'domestic_percent' => $billing->vatPercent(),
        ]);
    }
}
