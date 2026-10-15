<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StripeBillingProfile;
use Illuminate\Http\Request;

class AdminBillingProfileController extends Controller
{
    public function show($userId, StripeBillingProfile $billing)
    {
        $user = User::findOrFail($userId);
        if ($user->stripe_id && !config('billing.stripe_secret')) return $this->unavailable();
        try { return response()->json($billing->read($user)); }
        catch (\Throwable $e) { report($e); return response()->json(['message' => 'De administratiegegevens konden niet uit Stripe worden geladen. Probeer het opnieuw.'], 502); }
    }

    public function update(Request $request, $userId, StripeBillingProfile $billing)
    {
        $user = User::findOrFail($userId);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'phone' => 'nullable|string|max:50',
            'address' => 'required|array:line1,line2,postal_code,city,state,country',
            'address.line1' => 'nullable|string|max:255', 'address.line2' => 'nullable|string|max:255',
            'address.postal_code' => 'nullable|string|max:20', 'address.city' => 'nullable|string|max:100',
            'address.state' => 'nullable|string|max:100', 'address.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/', 'required_with:address.line1,address.postal_code,address.city,address.state'],
            'request_id' => 'required|uuid',
        ]);
        if (!config('billing.stripe_secret')) return $this->unavailable();
        $details = ['name' => trim($data['name']), 'email' => trim($data['email']), 'phone' => trim($data['phone'] ?? ''), 'address' => []];
        foreach (['line1', 'line2', 'postal_code', 'city', 'state', 'country'] as $key) $details['address'][$key] = trim($data['address'][$key] ?? '');
        try {
            return response()->json($billing->update($user, $details, $data['request_id']) + ['message' => 'Administratiegegevens opgeslagen en bijgewerkt in Stripe.']);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return response()->json(['message' => 'Stripe accepteert deze administratiegegevens niet. Controleer het adres en de contactgegevens. Er is niets lokaal opgeslagen.'], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Opslaan en synchroniseren met Stripe is niet voltooid. Je wijzigingen staan nog in het formulier; probeer het opnieuw.'], 502);
        }
    }

    private function unavailable() { return response()->json(['message' => 'Stripe is niet geconfigureerd. De administratiegegevens zijn niet opgeslagen.'], 503); }
}
