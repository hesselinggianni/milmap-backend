<?php

namespace App\Http\Controllers;

use App\Jobs\SendCampaignMessage;
use App\Mail\CampaignMail;
use App\Models\Lead;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailCategory;
use App\Models\MailCustomTemplate;
use App\Models\MailFollowup;
use App\Models\MailSend;
use App\Models\User;
use App\Services\MailTemplateRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin: beheer van e-mailcampagnes (lijst/bouw/preview/test/verzend), categorieën,
 * ontvanger-selectie (gebruikers + leads) en het verzendlog. Alle routes hangen onder
 * /api/v1/admin/* (admin.auth) zodat de frontend het admin-token meestuurt.
 */
class MailCampaignController extends Controller
{
    // ── Campagnes ────────────────────────────────────────────────────────
    public function index()
    {
        $campaigns = MailCampaign::with('category')
            ->withCount(['recipients', 'sends'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => $this->campaignPayload($c));

        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:160'],
            'description'    => ['nullable', 'string', 'max:500'],
            'category_id'    => ['nullable', 'integer', 'exists:mail_categories,id'],
            'template_key'   => ['required', 'string', 'max:64'],
            'default_locale' => ['nullable', 'string', 'max:5'],
            'auto_enroll_on_signup' => ['sometimes', 'boolean'],
        ]);

        if (! MailTemplateRegistry::exists($data['template_key'])) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 422);
        }

        $campaign = MailCampaign::create([
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'category_id'    => $data['category_id'] ?? null,
            'template_key'   => $data['template_key'],
            'default_locale' => $data['default_locale'] ?? 'nl',
            'auto_enroll_on_signup' => (bool) ($data['auto_enroll_on_signup'] ?? false),
            'status'         => 'draft',
            'created_by'     => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->campaignPayload($campaign->loadCount(['recipients', 'sends'])->load('category'))], 201);
    }

    public function show(int $id)
    {
        $campaign = MailCampaign::with('category')->withCount(['recipients', 'sends'])->findOrFail($id);

        return response()->json(['data' => $this->campaignPayload($campaign)]);
    }

    public function update(Request $request, int $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:160'],
            'description'    => ['nullable', 'string', 'max:500'],
            'category_id'    => ['nullable', 'integer', 'exists:mail_categories,id'],
            'template_key'   => ['sometimes', 'string', 'max:64'],
            'default_locale' => ['nullable', 'string', 'max:5'],
            'auto_enroll_on_signup' => ['sometimes', 'boolean'],
        ]);

        // De auto-enroll-toggle mag ALTIJD gewijzigd worden (ook op een lopende
        // funnel-campagne). Overige (inhoudelijke) velden alleen zolang de
        // campagne nog niet als losse blast is verzonden.
        $enrollOnly = array_keys($data) === ['auto_enroll_on_signup'];
        if (! $enrollOnly && ! in_array($campaign->status, ['draft', 'partial', 'failed'], true)) {
            return response()->json(['message' => 'Een verzonden campagne kan niet meer gewijzigd worden.'], 422);
        }

        if (isset($data['template_key']) && ! MailTemplateRegistry::exists($data['template_key'])) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 422);
        }

        $campaign->update($data);

        return response()->json(['data' => $this->campaignPayload($campaign->fresh()->loadCount(['recipients', 'sends'])->load('category'))]);
    }

    public function destroy(int $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        // Een verzonden campagne niet hard verwijderen — dat zou het verzendlog
        // (wie heeft ontvangen/geopend) cascade-wissen. Alleen concepten zonder
        // verzendgeschiedenis mogen weg.
        if ($campaign->sends()->exists()) {
            return response()->json(['message' => 'Deze campagne is al verzonden en kan niet verwijderd worden — het verzendlog blijft bewaard.'], 422);
        }
        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    // ── Template-registry ────────────────────────────────────────────────
    public function templates()
    {
        $items = collect(MailTemplateRegistry::all())->map(fn ($t, $key) => [
            'key'         => $key,
            'label'       => $t['label'] ?? $key,
            'description' => $t['description'] ?? null,
            'category'    => $t['category'] ?? null,
            'locales'     => array_values($t['locales'] ?? ['nl']),
            'subjects'    => $t['subjects'] ?? [],
            'custom'      => (bool) ($t['custom'] ?? false),
            'id'          => $t['id'] ?? null,
        ])->values();

        return response()->json(['data' => $items]);
    }

    public function previewTemplate(Request $request, string $key)
    {
        $resolved = MailTemplateRegistry::resolve($key, $request->query('locale', 'nl'));
        if (! $resolved) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 404);
        }

        $mail = new CampaignMail(
            $resolved['view'],
            $resolved['subject'],
            array_merge(
                ['name' => 'Voorbeeld', 'appUrl' => config('app.app_url', 'https://app.milmap.nl'), 'siteUrl' => 'https://milmap.nl'],
                $resolved['data'] ?? [] // custom-template blocks (leeg voor code-templates)
            ),
            '#', // dummy afmeldlink in preview
            null, // geen tracking-pixel in preview
        );

        return response()->json([
            'subject' => $resolved['subject'],
            'locale'  => $resolved['locale'],
            'html'    => $mail->render(),
        ]);
    }

    public function testTemplate(Request $request, string $key)
    {
        $data = $request->validate([
            'email'  => ['required', 'email', 'max:255'],
            'locale' => ['nullable', 'string', 'max:5'],
        ]);

        $resolved = MailTemplateRegistry::resolve($key, $data['locale'] ?? 'nl');
        if (! $resolved) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 404);
        }

        $mail = new CampaignMail(
            $resolved['view'],
            '[TEST] ' . $resolved['subject'],
            array_merge(
                ['name' => 'Test', 'appUrl' => config('app.app_url', 'https://app.milmap.nl'), 'siteUrl' => 'https://milmap.nl'],
                $resolved['data'] ?? []
            ),
            '#',
            null,
        );

        Mail::to($data['email'])->send($mail);

        return response()->json(['ok' => true, 'message' => 'Testmail verstuurd naar ' . $data['email']]);
    }

    // ── Zelf opgemaakte templates (blok-builder) ─────────────────────────
    // Deze lopen door DEZELFDE fundering: MailTemplateRegistry mergt ze in en ze
    // renderen via de gedeelde emails.custom-view (die emails.layout gebruikt),
    // dus ze werken meteen in campagnes, follow-ups, preview en verzenden.

    /** Ruwe custom-template voor de editor (per default-taal platgeslagen). */
    public function customShow(int $id)
    {
        return response()->json(['data' => $this->customPayload(MailCustomTemplate::findOrFail($id))]);
    }

    public function customStore(Request $request)
    {
        $data = $request->validate([
            'label'          => ['required', 'string', 'max:160'],
            'description'    => ['nullable', 'string', 'max:500'],
            'locale'         => ['nullable', 'string', 'max:5'],
            'subject'        => ['required', 'string', 'max:200'],
            'blocks'         => ['required', 'array', 'min:1'],
            'blocks.*.type'  => ['required', 'string'],
        ]);

        $locale = in_array($data['locale'] ?? 'nl', ['nl', 'en', 'de', 'fr', 'es', 'uk'], true) ? $data['locale'] : 'nl';

        // Uniek registry-sleutel afleiden uit het label (botst nooit met een
        // code-template of andere custom-template).
        $key = Str::slug($data['label'], '_') ?: 'template';
        $base = $key; $n = 2;
        while (MailTemplateRegistry::exists($key)) { $key = $base . '_' . $n; $n++; }

        $tpl = MailCustomTemplate::create([
            'label'       => $data['label'],
            'key'         => $key,
            'description' => $data['description'] ?? null,
            'locales'     => [$locale],
            'subjects'    => [$locale => $data['subject']],
            'blocks'      => [$locale => $this->sanitizeBlocks($request->input('blocks', []))],
            'created_by'  => $request->user()?->id,
        ]);
        MailTemplateRegistry::flush();

        return response()->json(['ok' => true, 'data' => $this->customPayload($tpl)], 201);
    }

    public function customUpdate(Request $request, int $id)
    {
        $tpl = MailCustomTemplate::findOrFail($id);

        $data = $request->validate([
            'label'         => ['required', 'string', 'max:160'],
            'description'   => ['nullable', 'string', 'max:500'],
            'locale'        => ['nullable', 'string', 'max:5'],
            'subject'       => ['required', 'string', 'max:200'],
            'blocks'        => ['required', 'array', 'min:1'],
            'blocks.*.type' => ['required', 'string'],
        ]);

        $locale = in_array($data['locale'] ?? 'nl', ['nl', 'en', 'de', 'fr', 'es', 'uk'], true) ? $data['locale'] : 'nl';

        // De sleutel blijft ongewijzigd (campagnes/follow-ups verwijzen ernaar).
        $tpl->update([
            'label'       => $data['label'],
            'description' => $data['description'] ?? null,
            'locales'     => [$locale],
            'subjects'    => [$locale => $data['subject']],
            'blocks'      => [$locale => $this->sanitizeBlocks($request->input('blocks', []))],
        ]);
        MailTemplateRegistry::flush();

        return response()->json(['ok' => true, 'data' => $this->customPayload($tpl->fresh())]);
    }

    public function customDestroy(int $id)
    {
        $tpl = MailCustomTemplate::findOrFail($id);

        $inUse = MailCampaign::where('template_key', $tpl->key)->exists()
            || MailFollowup::where('template_key', $tpl->key)->exists();
        if ($inUse) {
            return response()->json([
                'message' => 'Deze template is in gebruik door een campagne of follow-up en kan niet verwijderd worden.',
            ], 422);
        }

        $tpl->delete();
        MailTemplateRegistry::flush();

        return response()->json(['ok' => true]);
    }

    /** Afbeelding-upload voor de builder → gehoste absolute URL (nodig voor e-mail). */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        $path = $request->file('image')->store('mail', 'public');

        return response()->json([
            'ok'   => true,
            'url'  => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    /** Live preview van (nog niet opgeslagen) blocks. */
    public function customPreview(Request $request)
    {
        $request->validate([
            'blocks'  => ['required', 'array'],
            'subject' => ['nullable', 'string', 'max:200'],
        ]);

        $mail = new CampaignMail(
            'emails.custom',
            $request->input('subject', 'Voorbeeld'),
            [
                'name'   => 'Voorbeeld',
                'appUrl' => config('app.app_url', 'https://app.milmap.nl'),
                'siteUrl' => 'https://milmap.nl',
                'blocks' => $this->sanitizeBlocks($request->input('blocks', [])),
                'title'  => $request->input('subject', 'MilMap'),
            ],
            '#',
            null,
        );

        return response()->json(['html' => $mail->render()]);
    }

    /** Editor-vorm van een custom template (per default-taal platgeslagen). */
    private function customPayload(MailCustomTemplate $t): array
    {
        $loc = $t->locales[0] ?? 'nl';

        return [
            'id'          => $t->id,
            'key'         => $t->key,
            'label'       => $t->label,
            'description' => $t->description,
            'locale'      => $loc,
            'subject'     => $t->subjects[$loc] ?? '',
            'blocks'      => $t->blocks[$loc] ?? [],
        ];
    }

    /** Whitelist + normaliseer de blokken (voorkomt onveilige URL's/HTML). */
    private function sanitizeBlocks(array $blocks): array
    {
        $allowed = ['eyebrow', 'heading', 'paragraph', 'image', 'button', 'list', 'divider', 'spacer'];
        $safeUrl = fn ($u) => preg_match('#^https?://#i', trim((string) $u)) ? trim((string) $u) : '';
        $out = [];

        foreach ($blocks as $b) {
            if (! is_array($b)) continue;
            $type = $b['type'] ?? '';
            if (! in_array($type, $allowed, true)) continue;

            switch ($type) {
                case 'eyebrow':
                    $out[] = ['type' => 'eyebrow', 'text' => (string) ($b['text'] ?? ''), 'color' => $this->safeColor($b['color'] ?? null, '#2b7fff')];
                    break;
                case 'heading':
                    $out[] = ['type' => 'heading', 'text' => (string) ($b['text'] ?? '')];
                    break;
                case 'paragraph':
                    $out[] = ['type' => 'paragraph', 'text' => (string) ($b['text'] ?? '')];
                    break;
                case 'image':
                    $out[] = ['type' => 'image', 'url' => $safeUrl($b['url'] ?? ''), 'alt' => (string) ($b['alt'] ?? ''), 'href' => $safeUrl($b['href'] ?? '')];
                    break;
                case 'button':
                    $out[] = [
                        'type'  => 'button',
                        'label' => (string) ($b['label'] ?? 'Meer info'),
                        'url'   => $safeUrl($b['url'] ?? '') ?: '#',
                        'color' => $this->safeColor($b['color'] ?? null, '#2b7fff'),
                        'align' => in_array(($b['align'] ?? 'center'), ['left', 'center', 'right'], true) ? $b['align'] : 'center',
                    ];
                    break;
                case 'list':
                    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
                    $items = array_values(array_filter(array_map(fn ($i) => (string) $i, $items), fn ($i) => $i !== ''));
                    $out[] = ['type' => 'list', 'items' => $items];
                    break;
                case 'divider':
                    $out[] = ['type' => 'divider'];
                    break;
                case 'spacer':
                    $out[] = ['type' => 'spacer', 'size' => max(4, min(80, (int) ($b['size'] ?? 16)))];
                    break;
            }
        }

        return $out;
    }

    private function safeColor($c, string $default): string
    {
        $c = (string) $c;

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $default;
    }

    // ── Categorieën ──────────────────────────────────────────────────────
    public function categories()
    {
        return response()->json(['data' => MailCategory::orderBy('name')->get()]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'key'              => ['required', 'string', 'max:64', 'unique:mail_categories,key', 'regex:/^[a-z0-9_\-]+$/'],
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:255'],
            'is_transactional' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => MailCategory::create($data)], 201);
    }

    public function updateCategory(Request $request, int $id)
    {
        $cat = MailCategory::findOrFail($id);
        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:255'],
            'is_transactional' => ['nullable', 'boolean'],
        ]);
        $cat->update($data);

        return response()->json(['data' => $cat]);
    }

    public function destroyCategory(int $id)
    {
        MailCategory::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Contacten zoeken (gebruikers + leads) ────────────────────────────
    public function contacts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $out = [];

        $userQuery = User::query()->select('id', 'email', 'first_name', 'last_name', 'language');
        $leadQuery = Lead::query()->select('id', 'email');
        if ($q !== '') {
            $like = '%' . $q . '%';
            $userQuery->where(fn ($w) => $w->where('email', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like));
            $leadQuery->where('email', 'like', $like);
        }

        foreach ($userQuery->limit(20)->get() as $u) {
            $out[] = [
                'email'    => $u->email,
                'name'     => trim("{$u->first_name} {$u->last_name}") ?: null,
                'type'     => 'user',
                'user_id'  => $u->id,
                'lead_id'  => null,
                'language' => $u->language,
            ];
        }
        foreach ($leadQuery->limit(20)->get() as $l) {
            $out[] = [
                'email'    => $l->email,
                'name'     => null,
                'type'     => 'lead',
                'user_id'  => null,
                'lead_id'  => $l->id,
                'language' => null,
            ];
        }

        return response()->json(['data' => $out]);
    }

    // ── Ontvangers (staging) ─────────────────────────────────────────────
    public function recipients(int $id)
    {
        $campaign = MailCampaign::findOrFail($id);

        return response()->json([
            'data' => $campaign->recipients()->orderByDesc('id')->limit(1000)->get(),
            'total' => $campaign->recipients()->count(),
        ]);
    }

    public function addRecipients(Request $request, int $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        $mode = $request->input('mode', 'individual');

        $added = 0;
        if ($mode === 'individual') {
            $data = $request->validate([
                'contacts'             => ['required', 'array', 'min:1'],
                'contacts.*.email'     => ['required', 'email', 'max:255'],
                'contacts.*.name'      => ['nullable', 'string', 'max:160'],
                'contacts.*.user_id'   => ['nullable', 'integer'],
                'contacts.*.lead_id'   => ['nullable', 'integer'],
            ]);
            foreach ($data['contacts'] as $c) {
                $added += $this->upsertRecipient($campaign->id, mb_strtolower(trim($c['email'])), $c['user_id'] ?? null, $c['lead_id'] ?? null, $c['name'] ?? null, 'manual');
            }
        } elseif (in_array($mode, ['all_users', 'all_leads', 'everyone'], true)) {
            if ($mode !== 'all_leads') {
                User::select('id', 'email', 'first_name', 'last_name')->chunkById(500, function ($users) use ($campaign, &$added) {
                    foreach ($users as $u) {
                        $name = trim("{$u->first_name} {$u->last_name}") ?: null;
                        $added += $this->upsertRecipient($campaign->id, mb_strtolower(trim($u->email)), $u->id, null, $name, 'all_users');
                    }
                });
            }
            if ($mode !== 'all_users') {
                Lead::select('id', 'email')->chunkById(500, function ($leads) use ($campaign, &$added) {
                    foreach ($leads as $l) {
                        $added += $this->upsertRecipient($campaign->id, mb_strtolower(trim($l->email)), null, $l->id, null, 'all_leads');
                    }
                });
            }
        } else {
            return response()->json(['message' => 'Onbekende modus.'], 422);
        }

        return response()->json(['ok' => true, 'added' => $added, 'total' => $campaign->recipients()->count()]);
    }

    public function removeRecipient(int $id, int $recipientId)
    {
        MailCampaignRecipient::where('campaign_id', $id)->where('id', $recipientId)->delete();

        return response()->json(['ok' => true]);
    }

    // Handmatige lead toevoegen (hergebruikt de leads-tabel) en meteen aan de campagne hangen.
    public function addLead(Request $request, int $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name'  => ['nullable', 'string', 'max:160'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        $lead = Lead::firstOrCreate(['email' => $email], ['source' => 'admin-manual']);
        $this->upsertRecipient($campaign->id, $email, null, $lead->id, $data['name'] ?? null, 'manual');

        return response()->json(['ok' => true, 'total' => $campaign->recipients()->count()]);
    }

    // ── Verzenden ────────────────────────────────────────────────────────
    public function send(int $id)
    {
        $campaign = MailCampaign::with('category')->findOrFail($id);
        if (! MailTemplateRegistry::exists($campaign->template_key)) {
            return response()->json(['message' => 'De gekozen template bestaat niet meer.'], 422);
        }
        // Een categorie is verplicht vóór verzenden: zonder categorie zou een
        // per-categorie-afmelding niet meetellen (mail_sends.category_id NULL →
        // de opt-out-check matcht dan alleen globale afmeldingen). Zie de
        // is_transactional-vlag om transactionele mail expliciet uit te zonderen.
        if (! $campaign->category_id) {
            return response()->json(['message' => 'Kies eerst een categorie (nodig voor de afmeld-voorkeuren) voordat je verstuurt.'], 422);
        }

        $recipients = $campaign->recipients()->with('user:id,language')->get();
        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'Geen ontvangers geselecteerd.'], 422);
        }

        $dispatched = 0;
        DB::transaction(function () use ($campaign, $recipients, &$dispatched) {
            foreach ($recipients as $r) {
                $language = $r->user?->language ?: $campaign->default_locale ?: 'nl';
                $resolved = MailTemplateRegistry::resolve($campaign->template_key, $language, $campaign->default_locale ?: 'nl');

                // insertOrIgnore via firstOrCreate op de unique (campaign,followup_id,email).
                $send = MailSend::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'followup_id' => 0, 'email' => $r->email],
                    [
                        'user_id'      => $r->user_id,
                        'lead_id'      => $r->lead_id,
                        'name'         => $r->name,
                        'language'     => $resolved['locale'] ?? $language,
                        'template_key' => $campaign->template_key,
                        'category_id'  => $campaign->category_id,
                        'subject'      => $resolved['subject'] ?? null,
                        'status'       => 'pending',
                        'token'        => $this->newToken(),
                        'position'     => 0,
                    ],
                );

                if ($send->wasRecentlyCreated || $send->status === 'pending') {
                    $send->update(['status' => 'queued']);
                    SendCampaignMessage::dispatch($send->id);
                    $dispatched++;
                }
            }

            // De jobs staan in de wachtrij → de campagne is verstuurd. (Per-ontvanger
            // status staat in het verzendlog; we lockeren niet op 'sending'.)
            $campaign->update([
                'status'              => 'sent',
                'sent_at'             => $campaign->sent_at ?? now(),
                'recipients_built_at' => now(),
            ]);
        });

        return response()->json(['ok' => true, 'dispatched' => $dispatched]);
    }

    public function stats(int $id)
    {
        $campaign = MailCampaign::findOrFail($id);
        $base = MailSend::where('campaign_id', $id)->where('followup_id', 0);

        return response()->json([
            'data' => [
                'recipients'  => $campaign->recipients()->count(),
                'total'       => (clone $base)->count(),
                'sent'        => (clone $base)->where('status', 'sent')->count(),
                'queued'      => (clone $base)->whereIn('status', ['pending', 'queued'])->count(),
                'failed'      => (clone $base)->where('status', 'failed')->count(),
                'skipped'     => (clone $base)->where('status', 'skipped_optout')->count(),
                'opened'      => (clone $base)->whereNotNull('opened_at')->count(),
            ],
        ]);
    }

    public function sends(int $id)
    {
        $rows = MailSend::where('campaign_id', $id)
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'email', 'name', 'language', 'status', 'sent_at', 'opened_at', 'open_count', 'failure_reason', 'followup_id']);

        return response()->json(['data' => $rows]);
    }

    // ── Follow-ups ───────────────────────────────────────────────────────
    public function followups(int $id)
    {
        MailCampaign::findOrFail($id);

        return response()->json([
            'data' => MailFollowup::where('campaign_id', $id)->orderBy('position')->get()
                ->map(fn ($f) => $this->followupPayload($f)),
        ]);
    }

    public function storeFollowup(Request $request, int $id)
    {
        MailCampaign::findOrFail($id);
        $data = $request->validate([
            'template_key'       => ['required', 'string', 'max:64'],
            'parent_followup_id' => ['nullable', 'integer'],
            'category_id'        => ['nullable', 'integer', 'exists:mail_categories,id'],
            'delay_days'         => ['required', 'integer', 'min:0', 'max:365'],
            'condition'          => ['required', 'in:received,read,not_read,not_received'],
            'position'           => ['nullable', 'integer', 'min:1', 'max:20'],
            'subject_override'   => ['nullable', 'string', 'max:255'],
            'active'             => ['nullable', 'boolean'],
        ]);

        if (! MailTemplateRegistry::exists($data['template_key'])) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 422);
        }

        // Sub-follow-up: ouder moet bij dezelfde campagne horen en de boom mag ≤5 diep.
        $parentId = $data['parent_followup_id'] ?? null;
        if ($parentId !== null) {
            if (! MailFollowup::where('campaign_id', $id)->find($parentId)) {
                return response()->json(['message' => 'Onbekende ouder-follow-up.'], 422);
            }
            if ($this->nodeDepth($parentId) + 1 > 5) {
                return response()->json(['message' => 'Maximaal 5 niveaus follow-ups.'], 422);
            }
        }

        $data['campaign_id'] = $id;
        $data['position'] = $data['position'] ?? ((int) MailFollowup::where('campaign_id', $id)->max('position') + 1);
        $data['active'] = $data['active'] ?? true;

        $f = MailFollowup::create($data);

        return response()->json(['data' => $this->followupPayload($f)], 201);
    }

    public function updateFollowup(Request $request, int $id, int $fid)
    {
        $f = MailFollowup::where('campaign_id', $id)->findOrFail($fid);
        $data = $request->validate([
            'template_key'     => ['sometimes', 'string', 'max:64'],
            'category_id'      => ['nullable', 'integer', 'exists:mail_categories,id'],
            'delay_days'       => ['sometimes', 'integer', 'min:0', 'max:365'],
            'condition'        => ['sometimes', 'in:received,read,not_read,not_received'],
            'position'         => ['sometimes', 'integer', 'min:1', 'max:20'],
            'subject_override' => ['nullable', 'string', 'max:255'],
            'active'           => ['nullable', 'boolean'],
        ]);

        if (isset($data['template_key']) && ! MailTemplateRegistry::exists($data['template_key'])) {
            return response()->json(['message' => 'Onbekende mailtemplate.'], 422);
        }

        $f->update($data);

        return response()->json(['data' => $this->followupPayload($f->fresh())]);
    }

    public function destroyFollowup(int $id, int $fid)
    {
        MailFollowup::where('campaign_id', $id)->where('id', $fid)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Herstructureer de hele follow-up-boom (drag-and-drop): ontvangt de gewenste
     * platte staat [{id, parent_followup_id, position}] en valideert het resultaat
     * (alleen eigen follow-ups, geen cyclus, ≤5 diep) vóór persisteren.
     */
    public function reorderFollowups(Request $request, int $id)
    {
        MailCampaign::findOrFail($id);
        $data = $request->validate([
            'items'                      => ['required', 'array'],
            'items.*.id'                 => ['required', 'integer'],
            'items.*.parent_followup_id' => ['nullable', 'integer'],
            'items.*.position'           => ['required', 'integer', 'min:0'],
        ]);

        // Begin met de bestaande ouders, overschrijf met wat de UI stuurt.
        $own = MailFollowup::where('campaign_id', $id)->get(['id', 'parent_followup_id']);
        $ownSet = $own->pluck('id')->flip();
        $parentOf = $own->pluck('parent_followup_id', 'id')->all();

        foreach ($data['items'] as $it) {
            if (! isset($ownSet[$it['id']])) {
                return response()->json(['message' => 'Onbekende follow-up in de volgorde.'], 422);
            }
            $pid = $it['parent_followup_id'];
            if ($pid !== null && ! isset($ownSet[$pid])) {
                return response()->json(['message' => 'Ouder hoort niet bij deze campagne.'], 422);
            }
            if ($pid === $it['id']) {
                return response()->json(['message' => 'Een follow-up kan niet zijn eigen ouder zijn.'], 422);
            }
            $parentOf[$it['id']] = $pid;
        }

        // Cyclus- + dieptecheck over de resulterende boom.
        foreach (array_keys($parentOf) as $nid) {
            $depth = 0;
            $cur = $nid;
            $seen = [];
            while ($cur !== null) {
                if (isset($seen[$cur])) {
                    return response()->json(['message' => 'Cyclische follow-up-structuur.'], 422);
                }
                $seen[$cur] = true;
                if (++$depth > 5) {
                    return response()->json(['message' => 'Maximaal 5 niveaus follow-ups.'], 422);
                }
                $cur = $parentOf[$cur] ?? null;
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $it) {
                MailFollowup::where('id', $it['id'])->update([
                    'parent_followup_id' => $it['parent_followup_id'],
                    'position'           => $it['position'],
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    // Diepte van een bestaande follow-up-node (1 = top-level), via de ouderketen.
    private function nodeDepth(?int $id): int
    {
        $depth = 0;
        $guard = 0;
        while ($id && $guard++ < 12) {
            $n = MailFollowup::find($id);
            if (! $n) {
                break;
            }
            $depth++;
            $id = $n->parent_followup_id;
        }

        return $depth;
    }

    private function followupPayload(MailFollowup $f): array
    {
        $tpl = MailTemplateRegistry::get($f->template_key);

        return [
            'id'                 => $f->id,
            'parent_followup_id' => $f->parent_followup_id,
            'template_key'       => $f->template_key,
            'template_label'     => $tpl['label'] ?? $f->template_key,
            'category_id'        => $f->category_id,
            'delay_days'         => $f->delay_days,
            'condition'          => $f->condition,
            'position'           => $f->position,
            'subject_override'   => $f->subject_override,
            'active'             => $f->active,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function upsertRecipient(int $campaignId, string $email, ?int $userId, ?int $leadId, ?string $name, string $source): int
    {
        $rec = MailCampaignRecipient::firstOrCreate(
            ['campaign_id' => $campaignId, 'email' => $email],
            ['user_id' => $userId, 'lead_id' => $leadId, 'name' => $name, 'source' => $source],
        );

        // Verrijk een bestaande rij met user/lead-koppeling als die nog ontbrak.
        if (! $rec->wasRecentlyCreated) {
            $patch = [];
            if (! $rec->user_id && $userId) $patch['user_id'] = $userId;
            if (! $rec->lead_id && $leadId) $patch['lead_id'] = $leadId;
            if (! $rec->name && $name)      $patch['name'] = $name;
            if ($patch) $rec->update($patch);

            return 0;
        }

        return 1;
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(32);
        } while (MailSend::where('token', $token)->exists());

        return $token;
    }

    private function campaignPayload(MailCampaign $c): array
    {
        $tpl = MailTemplateRegistry::get($c->template_key);

        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'description'     => $c->description,
            'category_id'     => $c->category_id,
            'category'        => $c->category?->name,
            'template_key'    => $c->template_key,
            'template_label'  => $tpl['label'] ?? $c->template_key,
            'template_locales' => array_values($tpl['locales'] ?? ['nl']),
            'default_locale'  => $c->default_locale,
            'status'          => $c->status,
            'auto_enroll_on_signup' => (bool) $c->auto_enroll_on_signup,
            'recipients_count' => $c->recipients_count ?? 0,
            'sends_count'     => $c->sends_count ?? 0,
            'sent_at'         => $c->sent_at,
            'created_at'      => $c->created_at,
        ];
    }
}
