<?php

namespace App\Http\Controllers;

use App\Mail\PartnerAgreementCodeMail;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Partnerovereenkomst-flow: de partner accepteert de voorwaarden in het
 * portaal (datum/tijd + IP vastgelegd) en bevestigt dat daarna met een
 * 6-cijferige code die per e-mail wordt gestuurd — zo staat vast dat de
 * accounthouder zélf akkoord ging. Pas na bevestiging is de referral-link
 * te delen en te gebruiken (zie PartnerController/PartnerService-gating).
 */
class PartnerAgreementController extends Controller
{
    private const CODE_TTL_HOURS = 24;

    // ── GET /v1/partner/agreement ──────────────────────────────────
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()->partner));
    }

    // ── POST /v1/partner/agreement/accept ──────────────────────────
    // Legt de acceptatie vast en stuurt de bevestigingscode per mail.
    public function accept(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner->isApproved()) {
            return response()->json(['message' => 'Je aanmelding is nog niet goedgekeurd.'], 403);
        }
        if ($partner->agreement_confirmed_at) {
            return response()->json($this->payload($partner));
        }

        $request->validate(['accepted' => 'required|accepted']);

        $partner->forceFill([
            'agreement_accepted_at' => $partner->agreement_accepted_at ?? now(),
            'agreement_ip'          => $request->ip(),
        ])->save();

        $this->sendCode($partner);

        return response()->json($this->payload($partner));
    }

    // ── POST /v1/partner/agreement/resend ──────────────────────────
    public function resend(Request $request): JsonResponse
    {
        $partner = $request->user()->partner;

        if (! $partner->agreement_accepted_at || $partner->agreement_confirmed_at) {
            return response()->json(['message' => 'Er staat geen bevestiging open.'], 422);
        }

        $this->sendCode($partner);

        return response()->json($this->payload($partner));
    }

    // ── POST /v1/partner/agreement/confirm ─────────────────────────
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:12']);

        $partner = $request->user()->partner;

        if ($partner->agreement_confirmed_at) {
            return response()->json($this->payload($partner));
        }
        if (! $partner->agreement_accepted_at) {
            return response()->json(['message' => 'Accepteer eerst de overeenkomst.'], 422);
        }

        $code = preg_replace('/\D/', '', (string) $request->input('code'));

        if (! $partner->agreement_code
            || ! hash_equals($partner->agreement_code, $code)
            || ! $partner->agreement_code_expires_at
            || $partner->agreement_code_expires_at->isPast()
        ) {
            return response()->json(['message' => 'Ongeldige of verlopen code. Vraag zo nodig een nieuwe aan.'], 422);
        }

        $partner->forceFill([
            'agreement_confirmed_at'    => now(),
            'agreement_code'            => null,
            'agreement_code_expires_at' => null,
        ])->save();

        Log::info('[partner] overeenkomst bevestigd', [
            'partner'      => $partner->id,
            'accepted_at'  => $partner->agreement_accepted_at?->toISOString(),
            'confirmed_at' => $partner->agreement_confirmed_at?->toISOString(),
            'ip'           => $partner->agreement_ip,
        ]);

        return response()->json($this->payload($partner));
    }

    private function sendCode(Partner $partner): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $partner->forceFill([
            'agreement_code'            => $code,
            'agreement_code_expires_at' => now()->addHours(self::CODE_TTL_HOURS),
        ])->save();

        try {
            Mail::to($partner->user->email)->send(new PartnerAgreementCodeMail($partner, $code));
        } catch (\Throwable $e) {
            Log::warning('[partner] bevestigingscode-mail mislukt: ' . $e->getMessage());
        }
    }

    private function payload(Partner $partner): array
    {
        return [
            'accepted_at'  => $partner->agreement_accepted_at?->toISOString(),
            'confirmed_at' => $partner->agreement_confirmed_at?->toISOString(),
            'complete'     => $partner->agreementComplete(),
            'code_sent'    => (bool) $partner->agreement_code,
        ];
    }
}
