<?php

namespace App\Http\Controllers;

use App\Models\OcHrRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/*
 * Bijzonderheden (HR) — aparte module op oc.milmap.nl. Publiek indienen
 * (meerdere periodes per verzoek, kopie per mail); overzicht + verwijderen
 * alleen voor milmap-admins.
 */
class OcHrController extends Controller
{
    private const BACKUP_EMAIL = 'gianni@onavan.com';

    /** Verzoek indienen (publiek). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:150'],
            'email'             => ['required', 'email', 'max:190'],
            'items'             => ['required', 'array', 'min:1', 'max:31'],
            'items.*.from'      => ['required', 'date'],
            'items.*.to'        => ['required', 'date'],
            'items.*.from_time' => ['nullable', 'date_format:H:i'],
            'items.*.to_time'   => ['nullable', 'date_format:H:i'],
            'items.*.note'      => ['nullable', 'string', 'max:500'],
        ]);

        $items = [];
        $totalDays = 0;
        foreach ($data['items'] as $row) {
            $from = Carbon::parse($row['from'])->startOfDay();
            $to   = Carbon::parse($row['to'])->startOfDay();
            if ($to->lt($from)) {
                return response()->json(['message' => 'De tot-datum ligt vóór de van-datum.'], 422);
            }
            $days = $from->diffInDays($to) + 1;
            $totalDays += $days;
            $items[] = [
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'from_time' => $row['from_time'] ?? null,
                'to_time'   => $row['to_time'] ?? null,
                'days'      => $days,
                'note'      => $row['note'] ?? null,
            ];
        }

        $req = OcHrRequest::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'items'      => $items,
            'total_days' => $totalDays,
        ]);

        $this->sendMails($req);

        return response()->json(['request' => $req], 201);
    }

    /** Alle verzoeken — alleen admin. */
    public function adminIndex(Request $request)
    {
        $this->assertAdmin();

        return response()->json([
            'requests' => OcHrRequest::orderBy('name')->orderByDesc('id')->get(),
        ]);
    }

    public function adminDestroy(Request $request, int $id)
    {
        $this->assertAdmin();
        OcHrRequest::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    /* ── Helpers ── */

    private function assertAdmin(): void
    {
        $user = Auth::guard('sanctum')->user();
        abort_unless($user && $user->is_admin && $user->tokenCan('admin'), 403, 'Alleen voor milmap-admins.');
    }

    private function sendMails(OcHrRequest $req): void
    {
        $fmt = fn ($d) => Carbon::parse($d)->format('d-m-Y');
        $rows = collect($req->items)->map(function ($i) use ($fmt) {
            $fromT = !empty($i['from_time']) ? ' ' . $i['from_time'] : '';
            $toT   = !empty($i['to_time'])   ? ' ' . $i['to_time']   : '';
            return '<tr>'
                . '<td style="padding:5px 10px;border-bottom:1px solid #eee">' . $fmt($i['from']) . e($fromT) . '</td>'
                . '<td style="padding:5px 10px;border-bottom:1px solid #eee">' . $fmt($i['to']) . e($toT) . '</td>'
                . '<td style="padding:5px 10px;border-bottom:1px solid #eee;text-align:center">' . (int) $i['days'] . '</td>'
                . '<td style="padding:5px 10px;border-bottom:1px solid #eee">' . e($i['note'] ?: '—') . '</td>'
                . '</tr>';
        })->implode('');

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#222">'
            . '<h2>Bijzonderheden — verzoek ingediend</h2>'
            . '<p><strong>Naam:</strong> ' . e($req->name) . '<br>'
            . '<strong>E-mail:</strong> ' . e($req->email) . '<br>'
            . '<strong>Ingediend op:</strong> ' . $req->created_at->format('d-m-Y H:i') . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<thead><tr>'
            . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #ccc">Van</th>'
            . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #ccc">Tot</th>'
            . '<th style="padding:5px 10px;text-align:center;border-bottom:2px solid #ccc">Dagen</th>'
            . '<th style="padding:5px 10px;text-align:left;border-bottom:2px solid #ccc">Opmerking</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="margin-top:12px"><strong>Totaal: ' . $req->total_days . ' dag(en)</strong></p>'
            . '</div>';

        // Kopie naar de indiener.
        try {
            Mail::html($html, function ($m) use ($req) {
                $m->to($req->email)->subject('Kopie van je bijzonderheden-verzoek');
            });
        } catch (\Throwable $e) {
            \Log::warning('OC HR kopie-mail faalde: ' . $e->getMessage());
        }

        // Backup naar vast adres.
        try {
            Mail::html($html, function ($m) use ($req) {
                $m->to(self::BACKUP_EMAIL)->subject('Bijzonderheden-verzoek — ' . $req->name);
            });
        } catch (\Throwable $e) {
            \Log::warning('OC HR backup-mail faalde: ' . $e->getMessage());
        }
    }
}
