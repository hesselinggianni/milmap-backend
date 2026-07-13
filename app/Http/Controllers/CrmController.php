<?php

namespace App\Http\Controllers;

use App\Models\AgendaAppointment;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CrmController extends Controller
{
    /** Types die als "contactmoment" tellen en last_contact_at bijwerken. */
    private const CONTACT_TYPES = ['comment', 'call', 'email', 'meeting', 'demo', 'partner'];

    /**
     * Volledige pijplijn: fases + alle open/gesloten deals (met stoplicht).
     */
    public function index()
    {
        $deals = CrmDeal::orderBy('stage')->orderBy('position')->get()
            ->map(fn ($d) => $d->toApiArray())->values();

        return response()->json([
            'stages' => CrmDeal::STAGES,
            'deals'  => $deals,
            'summary' => [
                'open_value' => (int) CrmDeal::where('status', 'open')->sum('value'),
                'won_value'  => (int) CrmDeal::where('status', 'won')->sum('value'),
                'open_count' => CrmDeal::where('status', 'open')->count(),
            ],
        ], 200);
    }

    /** Eén deal met volledige tijdlijn. */
    public function show($id)
    {
        $deal = CrmDeal::findOrFail($id);

        return response()->json([
            'deal'       => $deal->toApiArray(),
            'activities' => $deal->activities()->with('creator')->get()
                ->map(fn ($a) => $a->toApiArray())->values(),
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $this->validateDeal($request);

        // Nieuwe deal onderaan de betreffende fase.
        $stage = $data['stage'] ?? 'lead';
        $data['position'] = (int) CrmDeal::where('stage', $stage)->max('position') + 1;
        $data['created_by'] = Auth::id();
        if (! empty($data['value'])) {
            $data['value'] = $this->toCents($data['value']);
        }

        $deal = CrmDeal::create($data);

        $deal->activities()->create([
            'type'        => 'system',
            'body'        => 'Deal aangemaakt.',
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);

        return response()->json(['deal' => $deal->toApiArray()], 201);
    }

    public function update(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);
        $data = $this->validateDeal($request, false);

        if (array_key_exists('value', $data) && $data['value'] !== null) {
            $data['value'] = $this->toCents($data['value']);
        }

        // Fasewijziging → status meebewegen + tijdlijn-notitie.
        if (! empty($data['stage']) && $data['stage'] !== $deal->stage) {
            $this->applyStageChange($deal, $data['stage']);
            $data['status'] = $this->statusForStage($data['stage']);
        }

        $deal->update($data);

        return response()->json(['deal' => $deal->fresh()->toApiArray()], 200);
    }

    public function destroy($id)
    {
        CrmDeal::findOrFail($id)->delete();
        return response()->json(['message' => 'Verwijderd'], 200);
    }

    /**
     * Herordenen na drag-and-drop: zet elke id in `ordered_ids` op `stage`
     * met zijn index als positie. Verwerkt zowel verslepen binnen een kolom
     * als naar een andere fase (fasewijziging wordt gelogd).
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'stage'         => 'required|string|max:40',
            'ordered_ids'   => 'required|array',
            'ordered_ids.*' => 'integer',
        ]);

        $stage = $data['stage'];
        foreach ($data['ordered_ids'] as $i => $id) {
            $deal = CrmDeal::find($id);
            if (! $deal) continue;

            if ($deal->stage !== $stage) {
                $this->applyStageChange($deal, $stage);
                $deal->status = $this->statusForStage($stage);
            }
            $deal->stage = $stage;
            $deal->position = $i;
            $deal->save();
        }

        return response()->json(['message' => 'Bijgewerkt'], 200);
    }

    /** Tijdlijn-item toevoegen (comment / gesprek / mail-notitie …). */
    public function addActivity(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);
        $data = $request->validate([
            'type'        => 'nullable|string|max:30',
            'body'        => 'required|string|max:5000',
            'goal'        => 'nullable|string|max:190',
            'happened_at' => 'nullable|date',
        ]);

        $type = $data['type'] ?? 'comment';
        $when = ! empty($data['happened_at']) ? \Illuminate\Support\Carbon::parse($data['happened_at']) : now();

        $activity = $deal->activities()->create([
            'type'        => $type,
            'body'        => $data['body'],
            'goal'        => $data['goal'] ?? null,
            'happened_at' => $when,
            'created_by'  => Auth::id(),
        ]);

        if (in_array($type, self::CONTACT_TYPES, true) && $when->lte(now())) {
            $deal->update(['last_contact_at' => $when]);
        }

        return response()->json(['activity' => $activity->fresh('creator')->toApiArray()], 201);
    }

    /**
     * 1-CLICK: demo-account aanmaken op het e-mailadres van de deal.
     * Maakt/hergebruikt een user, geeft N dagen premium-toegang, verifieert
     * het e-mailadres en stuurt een wachtwoord-instellink.
     */
    public function createDemo(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);
        $days = (int) ($request->input('days', 30));
        $days = max(1, min($days, 365));

        if (! $deal->contact_email) {
            return response()->json(['message' => 'Deze deal heeft geen e-mailadres.'], 422);
        }

        $user = User::where('email', $deal->contact_email)->first();
        $isNew = ! $user;

        if (! $user) {
            $parts = preg_split('/\s+/', trim((string) $deal->contact_name), 2);
            $user = User::create([
                'email'         => $deal->contact_email,
                'password'      => Hash::make(Str::random(32)),
                'first_name'    => $parts[0] ?? '',
                'last_name'     => $parts[1] ?? '',
                'trial_ends_at' => now()->addDays($days),
            ]);
        } else {
            $base = ($user->trial_ends_at && $user->trial_ends_at->isFuture())
                ? $user->trial_ends_at->copy() : now();
            $user->forceFill(['trial_ends_at' => $base->addDays($days)])->save();
        }
        $user->markEmailVerified();

        // Wachtwoord-instellink (best-effort), zelfde flow als partner-activatie.
        $setupUrl = null;
        try {
            $token   = Password::createToken($user);
            $appUrl  = rtrim(config('app.app_url', 'https://app.milmap.nl'), '/');
            $setupUrl = "{$appUrl}/password-reset?token={$token}&email=" . urlencode($user->email);
        } catch (\Throwable $e) {
            Log::warning('[crm] demo wachtwoord-token mislukt: ' . $e->getMessage());
        }

        try {
            $link = $setupUrl ?: 'https://app.milmap.nl/login';
            $html = "<p>Hoi" . ($deal->contact_name ? ' ' . e($deal->contact_name) : '') . ",</p>"
                . "<p>Je persoonlijke <strong>MilMap-demo</strong> staat klaar met {$days} dagen volledige toegang.</p>"
                . "<p><a href=\"{$link}\">Stel hier je wachtwoord in en log in</a></p>"
                . "<p>Veel plezier met verkennen — vragen? Antwoord gerust op deze mail.</p>";
            Mail::html($html, function ($m) use ($user) {
                $m->to($user->email)->subject('Je MilMap-demo staat klaar');
                $m->replyTo(config('mail.admin_mail', config('mail.from.address')));
            });
        } catch (\Throwable $e) {
            Log::warning('[crm] demo-mail mislukt: ' . $e->getMessage());
        }

        $deal->update(['user_id' => $user->id, 'last_contact_at' => now()]);
        $deal->activities()->create([
            'type'        => 'demo',
            'body'        => "Demo-account aangemaakt ({$days} dagen toegang)" . ($isNew ? ' — nieuw account.' : ' — bestaand account verlengd.'),
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Demo-account klaargezet en instructiemail verstuurd.',
            'deal'    => $deal->fresh()->toApiArray(),
        ], 200);
    }

    /**
     * 1-CLICK: e-mail sturen naar het contact vanuit de app.
     */
    public function sendEmail(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:10000',
        ]);

        if (! $deal->contact_email) {
            return response()->json(['message' => 'Deze deal heeft geen e-mailadres.'], 422);
        }

        try {
            $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.6;color:#0f172a">'
                . nl2br(e($data['body'])) . '</div>';
            Mail::html($html, function ($m) use ($deal, $data) {
                $m->to($deal->contact_email, $deal->contact_name ?: null)
                  ->subject($data['subject'])
                  ->replyTo(config('mail.admin_mail', config('mail.from.address')));
            });
        } catch (\Throwable $e) {
            Log::error('[crm] e-mail versturen mislukt: ' . $e->getMessage());
            return response()->json(['message' => 'E-mail versturen mislukt: ' . $e->getMessage()], 500);
        }

        $deal->update(['last_contact_at' => now()]);
        $deal->activities()->create([
            'type'        => 'email',
            'body'        => "📧 Mail verstuurd: {$data['subject']}\n\n{$data['body']}",
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);

        return response()->json([
            'message' => 'E-mail verstuurd.',
            'deal'    => $deal->fresh()->toApiArray(),
        ], 200);
    }

    /**
     * 1-CLICK: relatie omzetten naar partner (affiliate-portaal).
     */
    public function convertPartner(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);

        if ($deal->partner_id) {
            return response()->json(['message' => 'Deze relatie is al een partner.'], 422);
        }
        if (! $deal->contact_email) {
            return response()->json(['message' => 'Deze deal heeft geen e-mailadres.'], 422);
        }

        $user = User::where('email', $deal->contact_email)->first();
        if (! $user) {
            $parts = preg_split('/\s+/', trim((string) $deal->contact_name), 2);
            $user = User::create([
                'email'      => $deal->contact_email,
                'password'   => Hash::make(Str::random(32)),
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? '',
            ]);
        }

        $partner = Partner::where('user_id', $user->id)->first();
        if (! $partner) {
            $partner = Partner::create([
                'user_id'       => $user->id,
                'company_name'  => $deal->company_name,
                'referral_code' => Partner::generateReferralCode($deal->company_name ?: ($deal->contact_name ?: 'MilMap')),
                'status'        => Partner::STATUS_PENDING,
                'website'       => $deal->company_website,
                'description'   => 'Aangemaakt vanuit de sales-pijplijn.',
            ]);
        }

        $deal->update(['partner_id' => $partner->id, 'last_contact_at' => now()]);
        $deal->activities()->create([
            'type'        => 'partner',
            'body'        => 'Omgezet naar partner (referral-code ' . $partner->referral_code . '). Nog goed te keuren in het Partners-tabblad.',
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Relatie omgezet naar partner (' . $partner->referral_code . ').',
            'deal'    => $deal->fresh()->toApiArray(),
        ], 200);
    }

    /**
     * Vervolgactie in de agenda plaatsen + next_action_at op de deal zetten.
     */
    public function scheduleTask(Request $request, $id)
    {
        $deal = CrmDeal::findOrFail($id);
        $data = $request->validate([
            'title'     => 'nullable|string|max:160',
            'starts_at' => 'required|date',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $title = $data['title'] ?: ('Opvolgen: ' . $deal->title);

        AgendaAppointment::create([
            'title'      => $title,
            'notes'      => ($data['notes'] ?? '') . "\n\nSales-deal: {$deal->title}" . ($deal->contact_email ? " ({$deal->contact_email})" : ''),
            'starts_at'  => $data['starts_at'],
            'all_day'    => false,
            'color'      => '#2b7fff',
            'created_by' => Auth::id(),
        ]);

        $deal->update(['next_action_at' => $data['starts_at']]);
        $deal->activities()->create([
            'type'        => 'task',
            'body'        => '📅 Vervolgactie ingepland: ' . $title,
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Taak in de agenda gezet.',
            'deal'    => $deal->fresh()->toApiArray(),
        ], 200);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function validateDeal(Request $request, bool $creating = true): array
    {
        $rules = [
            'title'           => ($creating ? 'required' : 'sometimes') . '|string|max:190',
            'contact_name'    => 'nullable|string|max:160',
            'contact_email'   => 'nullable|email|max:190',
            'contact_phone'   => 'nullable|string|max:60',
            'contact_role'    => 'nullable|string|max:120',
            'company_name'    => 'nullable|string|max:190',
            'company_website' => 'nullable|string|max:190',
            'company_address' => 'nullable|string|max:255',
            'company_size'    => 'nullable|string|max:60',
            'value'           => 'nullable|numeric|min:0',
            'currency'        => 'nullable|string|max:3',
            'category'        => 'nullable|string|max:60',
            'tags'            => 'nullable|array',
            'tags.*'          => 'string|max:40',
            'stage'           => 'nullable|string|max:40',
            'status'          => 'nullable|string|max:20',
            'goal'            => 'nullable|string|max:190',
            'notes'           => 'nullable|string|max:5000',
            'next_action_at'  => 'nullable|date',
        ];

        return $request->validate($rules);
    }

    /** Euro's (of centen-string) → centen. Accepteert 9.99 of 999. */
    private function toCents($value): int
    {
        // Frontend stuurt hele euro's als getal; sla op in centen.
        return (int) round(((float) $value) * 100);
    }

    private function statusForStage(string $stage): string
    {
        return match ($stage) {
            'won'  => 'won',
            'lost' => 'lost',
            default => 'open',
        };
    }

    private function applyStageChange(CrmDeal $deal, string $newStage): void
    {
        $labels = collect(CrmDeal::STAGES)->keyBy('key');
        $from = $labels[$deal->stage]['label'] ?? $deal->stage;
        $to   = $labels[$newStage]['label'] ?? $newStage;

        $deal->activities()->create([
            'type'        => 'stage',
            'body'        => "Fase gewijzigd: {$from} → {$to}",
            'happened_at' => now(),
            'created_by'  => Auth::id(),
        ]);
    }
}
