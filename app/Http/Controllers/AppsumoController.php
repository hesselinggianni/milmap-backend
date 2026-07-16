<?php

namespace App\Http\Controllers;

use App\Models\AppsumoCode;
use App\Models\User;
use App\Services\AppsumoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * AppSumo lifetime-deal: code valideren + verzilveren.
 *
 * Flow (app.milmap.nl/appsumo):
 *   1. GET  /api/v1/appsumo/validate/{code}  → is de code geldig & vrij?
 *   2. POST /api/v1/appsumo/redeem           → account aanmaken + 100%-abonnement
 */
class AppsumoController extends Controller
{
    public function __construct(private AppsumoService $appsumo)
    {
    }

    /**
     * Snelle check zodat de frontend de code kan bevestigen vóór het account-
     * formulier. Onthult niets gevoeligs — alleen of de code verzilverd kan worden.
     */
    public function validateCode(string $code)
    {
        $row = $this->appsumo->findRedeemable($code);

        return response()->json([
            'valid' => (bool) $row,
            'code'  => $row?->code,
        ]);
    }

    /**
     * Verzilver een code: maak het MilMap-account aan en zet het 100%-korting-
     * abonnement op het AppSumo-product. Retourneert token + user (auto-login),
     * net als de gewone registratie.
     */
    public function redeem(Request $request)
    {
        $data = $request->validate([
            'code'     => 'required|string|max:200',
            'email'    => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! $this->appsumo->isConfigured()) {
            throw ValidationException::withMessages([
                'code' => ['Verzilveren is tijdelijk niet beschikbaar. Probeer het later opnieuw.'],
            ]);
        }

        // Code moet bestaan én vrij zijn. Bewust dezelfde generieke melding voor
        // "onbekend" en "al gebruikt" zodat codes niet te bruteforcen zijn.
        $code = $this->appsumo->findRedeemable($data['code']);
        if (! $code) {
            throw ValidationException::withMessages([
                'code' => ['Deze code is ongeldig of al gebruikt.'],
            ]);
        }

        // E-mail moet vrij zijn (gearchiveerde accounts geven we niet vrij in deze
        // flow — houd het simpel: één AppSumo-verzilvering per nieuw e-mailadres).
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Er bestaat al een account met dit e-mailadres. Log in en neem contact op.'],
            ]);
        }

        try {
            $user = DB::transaction(function () use ($data, $code) {
                // Codes één voor één verzilveren: lock de rij en her-check binnen de
                // transactie zodat twee gelijktijdige verzoeken 'm niet beide pakken.
                $locked = AppsumoCode::whereKey($code->id)
                    ->where('status', AppsumoCode::STATUS_AVAILABLE)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    throw ValidationException::withMessages([
                        'code' => ['Deze code is ongeldig of al gebruikt.'],
                    ]);
                }

                $user = User::create([
                    'email'         => $data['email'],
                    'password'      => Hash::make($data['password']),
                    'trial_ends_at' => now()->addDays(User::APP_TRIAL_DAYS),
                ]);

                // Stripe-abonnement + code verbranden. Faalt dit, dan draait de hele
                // transactie terug (geen weesaccount, code blijft vrij).
                $this->appsumo->redeem($locked, $user);

                return $user;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[appsumo] verzilveren mislukt', [
                'code' => $code->code, 'email' => $data['email'], 'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'code' => ['Verzilveren is niet gelukt. Probeer het opnieuw of neem contact op.'],
            ]);
        }

        $token = $user->createToken('API Token', ['user'])->plainTextToken;

        return response()->json([
            'message' => 'AppSumo code redeemed successfully.',
            'user'    => array_merge($user->fresh()->toArray(), [
                'verification' => $user->verificationState(),
                'premium'      => $user->premiumState(),
            ]),
            'token'   => $token,
        ], 201);
    }
}
