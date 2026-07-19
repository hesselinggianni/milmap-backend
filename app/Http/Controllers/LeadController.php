<?php

namespace App\Http\Controllers;

use App\Mail\AppDownloadMail;
use App\Models\Lead;
use App\Services\CrmLeadPushService;
use App\Services\LaunchCouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Publieke leads-API — gebruikers die via milmap.nl/app het e-mailformulier
 * invullen om op de hoogte te blijven van de release. Geen account; alleen
 * e-mail + lichte attributie (source label + utm_source = share_uuid van een
 * doorverwijzer). Resource leeft in een aparte tabel zodat het admin-overzicht
 * niet vermengt met echte gebruikers.
 *
 * Het admin-zicht (LeadController::adminIndex) hangt onder /admin/leads en
 * vereist admin.auth.
 */
class LeadController extends Controller
{
    /**
     * POST /api/v1/leads — publiek (throttled). Idempotent: bestaande e-mail
     * krijgt 200 ok zonder error, zodat een dubbele tap niet vervelend voelt.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email'      => ['required', 'email', 'max:255'],
            'source'     => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:64'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $lead = Lead::firstOrCreate(
            ['email' => $email],
            [
                'source'     => $data['source'] ?? 'app-landing',
                'utm_source' => $data['utm_source'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        // Nieuwe leads ook in de Sales-pijplijn zetten (fase "Lead") zodat
        // sales ze direct kan opvolgen vanuit admin → Sales / CRM.
        if ($lead->wasRecentlyCreated) {
            app(CrmLeadPushService::class)->push($lead);
        }

        // Exit-intent op /pricing belooft "we mailen je 50% korting" — die mail
        // moet dus direct komen, niet pas na handmatige admin-actie. Eén keer
        // per e-mailadres (notified_at is de grendel); mint een unieke Stripe-
        // code en stuur de bestaande download/korting-mail. Faalt dit (Stripe
        // niet geconfigureerd, mail stuk), dan blijft de lead gewoon staan en
        // kan admin alsnog handmatig versturen.
        $couponSent = false;
        if (($data['source'] ?? null) === 'pricing-exit' && ! $lead->notified_at) {
            $couponSent = $this->sendCouponMail($lead);
        }

        return response()->json([
            'ok'       => true,
            'is_new'   => $lead->wasRecentlyCreated,
            'message'  => $couponSent
                ? 'Check je inbox — je persoonlijke kortingscode is onderweg.'
                : ($lead->wasRecentlyCreated
                    ? 'Bedankt! Je hoort van ons zodra MilMap live gaat.'
                    : 'Je staat al op de lijst — we sturen je een mail zodra MilMap live gaat.'),
        ]);
    }

    /**
     * Mint een unieke 50%-jaarcode en mail die naar de lead; markeert de lead
     * als geïnformeerd. Stil falen (false) — de aanroeper bepaalt de boodschap.
     */
    private function sendCouponMail(Lead $lead): bool
    {
        try {
            $result = app(LaunchCouponService::class)->createYearlyHalfOffCode($lead->email);
            Mail::to($lead->email)->send(new AppDownloadMail(
                couponCode: $result['code'],
                couponExpiresLabel: $result['expires_at']->locale('nl')->isoFormat('D MMMM YYYY'),
                appStoreUrl: config('milmap.app_store_url'),
                playStoreUrl: config('milmap.play_store_url'),
                webAppUrl: config('milmap.web_app_url'),
            ));
        } catch (\Throwable $e) {
            report($e);
            return false;
        }

        $lead->notified_at = now();
        $lead->save();

        return true;
    }

    /**
     * GET /api/v1/admin/leads — admin-only overzicht met paginering + filters.
     * Query params:
     *   q       — substring match op e-mail
     *   source  — exact filter op de source-label
     *   pending — 1 → alleen leads zonder notified_at
     */
    public function adminIndex(Request $request)
    {
        $request->validate([
            'q'       => 'nullable|string|max:255',
            'source'  => 'nullable|string|max:64',
            'pending' => 'nullable|in:0,1',
            'page'    => 'nullable|integer|min:1',
            'per'     => 'nullable|integer|min:1|max:200',
        ]);

        $per = (int) $request->query('per', 25);

        $q = Lead::query();
        if ($qs = $request->query('q')) {
            $q->where('email', 'like', '%' . $qs . '%');
        }
        if ($s = $request->query('source')) {
            $q->where('source', $s);
        }
        if ($request->query('pending') === '1') {
            $q->whereNull('notified_at');
        }

        $page = $q->orderByDesc('id')->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => [
                'id'          => $l->id,
                'email'       => $l->email,
                'source'      => $l->source,
                'utm_source'  => $l->utm_source,
                'ip_address'  => $l->ip_address,
                'notified_at' => optional($l->notified_at)->toIso8601String(),
                'created_at'  => optional($l->created_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $page->total(),
                'page'  => $page->currentPage(),
                'per'   => $page->perPage(),
                'pages' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Markeer één lead als "we hebben 'm geïnformeerd". Optioneel handmatig
     * vanuit het admin-paneel; de daadwerkelijke release-mail-stack komt
     * later, dit endpoint is voor handmatige bewerking.
     */
    public function adminMarkNotified(Request $request, int $id)
    {
        $lead = Lead::findOrFail($id);
        $lead->notified_at = now();
        $lead->save();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/admin/leads/{id}/send-download-mail — verstuur de
     * app-download-mail handmatig naar deze lead: mint een unieke Stripe-
     * promotiecode (50% op het jaarabonnement, 1x, 1 jaar geldig), verstuurt de
     * Apple-clean mail en markeert de lead als geïnformeerd.
     */
    public function adminSendDownloadMail(int $id, LaunchCouponService $coupons)
    {
        $lead = Lead::findOrFail($id);

        try {
            $result = $coupons->createYearlyHalfOffCode($lead->email);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Kon geen kortingscode aanmaken: ' . $e->getMessage(),
            ], 422);
        }

        try {
            Mail::to($lead->email)->send(new AppDownloadMail(
                couponCode: $result['code'],
                couponExpiresLabel: $result['expires_at']->locale('nl')->isoFormat('D MMMM YYYY'),
                appStoreUrl: config('milmap.app_store_url'),
                playStoreUrl: config('milmap.play_store_url'),
                webAppUrl: config('milmap.web_app_url'),
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Versturen mislukt: ' . $e->getMessage(),
            ], 500);
        }

        $lead->notified_at = now();
        $lead->save();

        return response()->json([
            'ok'          => true,
            'code'        => $result['code'],
            'notified_at' => $lead->notified_at->toIso8601String(),
        ]);
    }

    /**
     * Verwijder een lead — bv. bij uitschrijven-verzoek.
     */
    public function adminDestroy(int $id)
    {
        Lead::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
