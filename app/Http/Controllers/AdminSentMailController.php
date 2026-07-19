<?php

namespace App\Http\Controllers;

use App\Models\SentEmail;
use Illuminate\Http\Request;

/**
 * Admin-inzage in het universele uitgaand-maillog (sent_emails, gevuld door de
 * MessageSent-listener in AppServiceProvider): welke mails zijn wanneer naar
 * welk adres/welke gebruiker gestuurd. Alleen lezen — rijen ontstaan enkel bij
 * daadwerkelijke verzending.
 */
class AdminSentMailController extends Controller
{
    /**
     * GET /api/v1/admin/sent-mails — gepagineerd overzicht.
     * Query params:
     *   q       — substring match op e-mailadres of onderwerp
     *   email   — exact filter op ontvanger (voor user/lead-detail)
     *   user_id — filter op gekoppelde gebruiker
     */
    public function index(Request $request)
    {
        $request->validate([
            'q'       => 'nullable|string|max:255',
            'email'   => 'nullable|string|max:255',
            'user_id' => 'nullable|integer',
            'page'    => 'nullable|integer|min:1',
            'per'     => 'nullable|integer|min:1|max:200',
        ]);

        // body_html expliciet buiten de select houden — MEDIUMTEXT × 50 rijen
        // maakt de lijst anders onnodig zwaar; de body komt via show().
        $q = SentEmail::query()
            ->select(['id', 'email', 'user_id', 'lead_id', 'token', 'subject', 'mailer', 'status',
                      'sent_at', 'opened_at', 'open_count', 'click_count', 'last_click_url'])
            ->with('user:id,first_name,last_name,email')
            ->with(['clicks' => fn ($c) => $c->limit(20)]);

        if ($qs = $request->query('q')) {
            $q->where(fn ($w) => $w
                ->where('email', 'like', '%' . $qs . '%')
                ->orWhere('subject', 'like', '%' . $qs . '%'));
        }
        if ($email = $request->query('email')) {
            $q->where('email', mb_strtolower(trim($email)));
        }
        if ($userId = $request->query('user_id')) {
            $q->where('user_id', $userId);
        }

        $page = $q->orderByDesc('sent_at')->orderByDesc('id')
            ->paginate((int) $request->query('per', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($m) => [
                'id'             => $m->id,
                'email'          => $m->email,
                'subject'        => $m->subject,
                'user_id'        => $m->user_id,
                'user_name'      => $m->user ? trim(($m->user->first_name ?? '') . ' ' . ($m->user->last_name ?? '')) ?: null : null,
                'lead_id'        => $m->lead_id,
                'status'         => $m->status,
                'sent_at'        => optional($m->sent_at)->toIso8601String(),
                'opened_at'      => optional($m->opened_at)->toIso8601String(),
                'open_count'     => (int) $m->open_count,
                'click_count'    => (int) $m->click_count,
                'last_click_url' => $m->last_click_url,
                'clicks'         => $m->clicks->map(fn ($c) => [
                    'url'        => $c->url,
                    'label'      => $c->label,
                    'clicked_at' => optional($c->clicked_at)->toIso8601String(),
                ])->values(),
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
     * GET /api/v1/admin/sent-mails/{id} — één mail inclusief de exact verzonden
     * HTML-body (voor de preview-dialog). De open-pixel wordt uit de weergave
     * gestript: anders telt élke admin-preview als een "open" van de ontvanger.
     * De klik-links blijven staan (visueel identiek); de dialog rendert in een
     * sandboxed iframe zodat er niets kan navigeren of draaien.
     */
    public function show(int $id)
    {
        $m = SentEmail::with(['clicks' => fn ($c) => $c->limit(50)])->findOrFail($id);

        $body = $m->body_html;
        if (is_string($body)) {
            $body = preg_replace('#<img[^>]*/m/o2/[^>]*>#i', '', $body);
        }

        return response()->json([
            'id'          => $m->id,
            'email'       => $m->email,
            'subject'     => $m->subject,
            'status'      => $m->status,
            'sent_at'     => optional($m->sent_at)->toIso8601String(),
            'opened_at'   => optional($m->opened_at)->toIso8601String(),
            'open_count'  => (int) $m->open_count,
            'click_count' => (int) $m->click_count,
            'body_html'   => $body,
        ]);
    }
}
