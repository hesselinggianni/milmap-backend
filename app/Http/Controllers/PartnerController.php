<?php

namespace App\Http\Controllers;

use App\Mail\NewPartnerApplicationMail;
use App\Mail\PartnerApplicationReceivedMail;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Publieke partner-endpoints: aanmelden als partner (partners.milmap.nl) en
 * het valideren van een referral-code tijdens de registratie-/checkout-flow.
 */
class PartnerController extends Controller
{
    // ── POST /v1/partners/apply ────────────────────────────────────
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|string|email|max:255',
            'company_name' => 'nullable|string|max:255',
            'website'      => 'nullable|url|max:255',
            'description'  => 'required|string|max:1000',
        ]);

        // Bestaand milmap-account? Dan koppelen we het partnerprofiel daaraan;
        // anders maken we een account aan en laat de mail een wachtwoord instellen.
        $user      = User::where('email', $validated['email'])->first();
        $isNewUser = ! $user;

        if ($user?->partner) {
            return response()->json([
                'message' => 'Er bestaat al een partneraanmelding voor dit e-mailadres.',
            ], 422);
        }

        if (! $user) {
            $parts = preg_split('/\s+/', trim($validated['name']), 2);
            $user  = User::create([
                'email'      => $validated['email'],
                'password'   => Hash::make(Str::random(32)),
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? '',
            ]);
        }

        $partner = Partner::create([
            'user_id'       => $user->id,
            'company_name'  => $validated['company_name'] ?? null,
            'referral_code' => Partner::generateReferralCode($validated['company_name'] ?: $validated['name']),
            'status'        => Partner::STATUS_PENDING,
            'website'       => $validated['website'] ?? null,
            'description'   => $validated['description'],
        ]);

        // Voor een nieuw account: wachtwoord-instellink meesturen zodat de
        // partner straks op het portaal kan inloggen (zelfde reset-flow als
        // de activatiemail na guest-checkout).
        $setupUrl = null;
        if ($isNewUser) {
            try {
                $token      = Password::createToken($user);
                $partnerUrl = rtrim(config('app.partner_url', 'https://partners.milmap.nl'), '/');
                $appUrl     = rtrim(config('app.app_url', 'https://app.milmap.nl'), '/');
                $setupUrl   = "{$appUrl}/password-reset?token={$token}&email=" . urlencode($user->email)
                    . '&redirect=' . urlencode($partnerUrl);
            } catch (\Throwable $e) {
                Log::warning('[partner] kon wachtwoord-token niet aanmaken: ' . $e->getMessage());
            }
        }

        // Bevestiging naar de partner + notificatie naar de admin. Best-effort:
        // een mailstoring mag de aanmelding nooit breken.
        try {
            Mail::to($user->email)->send(new PartnerApplicationReceivedMail($partner, $setupUrl));
        } catch (\Throwable $e) {
            Log::warning('[partner] bevestigingsmail mislukt: ' . $e->getMessage());
        }
        try {
            Mail::to('hesselinggianni@gmail.com')->send(new NewPartnerApplicationMail($partner));
        } catch (\Throwable $e) {
            Log::warning('[partner] admin-notificatie mislukt: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Aanmelding ontvangen. Je hoort snel van ons.',
        ], 201);
    }

    // ── GET /v1/partners/referral/{code} ───────────────────────────
    // Publiek — de registratiepagina toont hiermee de korting + partnernaam.
    public function validateReferral(string $code): JsonResponse
    {
        $partner = Partner::where('referral_code', strtoupper(trim($code)))
            ->where('status', Partner::STATUS_APPROVED)
            ->first();

        // De link is pas actief nadat de partner de overeenkomst heeft
        // geaccepteerd én per mail bevestigd.
        if (! $partner || ! $partner->agreementComplete()) {
            return response()->json(['valid' => false], 404);
        }

        return response()->json([
            'valid'         => true,
            'discount_rate' => $partner->discount_rate,
            'partner_name'  => $partner->company_name
                ?: trim("{$partner->user->first_name} {$partner->user->last_name}"),
        ]);
    }
}
