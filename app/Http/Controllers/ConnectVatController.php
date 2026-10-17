<?php
namespace App\Http\Controllers;

use App\Models\ConnectOrganization;
use App\Services\ConnectVat;
use Illuminate\Http\Request;

/** Live controle van een btw-nummer in de Connect-checkout (VIES), alleen voor beheerders van de organisatie. */
class ConnectVatController extends Controller
{
    public function check(Request $r, ConnectOrganization $organization, ConnectVat $vat)
    {
        abort_unless($organization->members()->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->exists(), 403);
        $data = $r->validate(['country' => 'required|string|size:2|regex:/^[A-Za-z]{2}$/', 'vat_id' => 'required|string|max:32']);
        $country = strtoupper($data['country']);
        $vatId = ConnectVat::normalize($data['vat_id']);
        if (!ConnectVat::isEu($country)) return response()->json(['vat_id' => $vatId, 'valid' => null, 'reason' => 'outside_eu']);
        if (!ConnectVat::wellFormed($country, $vatId)) {
            return response()->json(['vat_id' => $vatId, 'valid' => false, 'reason' => 'format',
                'message' => 'Een btw-nummer uit dit land begint met '.ConnectVat::prefix($country).'.']);
        }
        $result = $vat->check($country, $vatId);
        return response()->json(['vat_id' => $vatId, 'valid' => $result['valid'], 'name' => $result['name'],
            'reason' => $result['valid'] === null ? 'unavailable' : null]);
    }
}
