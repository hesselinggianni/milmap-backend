<?php

namespace App\Http\Controllers;

use App\Models\AgendaAppointment;
use App\Models\Lead;
use App\Models\MailCampaign;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AdminAgendaController — voedt de admin-agenda.
 *
 * events() bundelt vier bronnen tot één lijst kalender-events voor een
 * datumbereik:
 *   • eigen afspraken           (bewerkbaar, tabel agenda_appointments)
 *   • geplande campagne-verzending (mail gaat verstuurd worden — scheduled_at)
 *   • verzonden campagnes        (sent_at)
 *   • aangemelde leads           (created_at)
 * De laatste drie zijn read-only; alleen afspraken kun je hier muteren.
 */
class AdminAgendaController extends Controller
{
    /** Kleuren per event-type — spiegelt de legenda in de frontend. */
    private const COLORS = [
        'appointment'        => '#6366f1', // indigo
        'campaign_scheduled' => '#f59e0b', // amber
        'campaign_sent'      => '#22c55e', // groen
        'lead'               => '#3b82f6', // blauw
    ];

    public function events(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        return response()->json(['events' => $this->gatherEvents($from, $to)]);
    }

    /**
     * Verzamel alle kalender-events (afspraken + campagnes + leads) in een
     * datumbereik. Gedeeld door events() (JSON voor de admin-agenda) en
     * ical() (iCal-abonnee-feed).
     */
    private function gatherEvents(Carbon $from, Carbon $to): array
    {
        $events = [];

        // 1) Eigen afspraken (bewerkbaar).
        foreach (AgendaAppointment::whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get() as $a) {
            $events[] = [
                'id'             => 'appt-' . $a->id,
                'type'           => 'appointment',
                'title'          => $a->title,
                'start'          => optional($a->starts_at)->toIso8601String(),
                'end'            => optional($a->ends_at)->toIso8601String(),
                'all_day'        => (bool) $a->all_day,
                'color'          => $a->color ?: self::COLORS['appointment'],
                'editable'       => true,
                'appointment_id' => $a->id,
                'notes'          => $a->notes,
                'location'       => $a->location,
            ];
        }

        // 2) Campagnes die nog verstuurd gaan worden (geplande verzending).
        $scheduled = MailCampaign::whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->get();
        foreach ($scheduled as $c) {
            $events[] = [
                'id'       => 'camp-sched-' . $c->id,
                'type'     => 'campaign_scheduled',
                'title'    => $c->name,
                'start'    => optional($c->scheduled_at)->toIso8601String(),
                'end'      => null,
                'all_day'  => false,
                'color'    => self::COLORS['campaign_scheduled'],
                'editable' => false,
                'status'   => $c->status,
                'link'     => '/campaigns',
            ];
        }

        // 3) Verzonden campagnes.
        $sent = MailCampaign::whereNotNull('sent_at')
            ->whereBetween('sent_at', [$from, $to])
            ->get();
        foreach ($sent as $c) {
            $events[] = [
                'id'       => 'camp-sent-' . $c->id,
                'type'     => 'campaign_sent',
                'title'    => $c->name,
                'start'    => optional($c->sent_at)->toIso8601String(),
                'end'      => null,
                'all_day'  => false,
                'color'    => self::COLORS['campaign_sent'],
                'editable' => false,
                'status'   => $c->status,
                'link'     => '/campaigns',
            ];
        }

        // 4) Aangemelde leads.
        $leads = Lead::whereBetween('created_at', [$from, $to])->get();
        foreach ($leads as $l) {
            $events[] = [
                'id'       => 'lead-' . $l->id,
                'type'     => 'lead',
                'title'    => $l->email ?: 'Lead',
                'start'    => optional($l->created_at)->toIso8601String(),
                'end'      => null,
                'all_day'  => false,
                'color'    => self::COLORS['lead'],
                'editable' => false,
                'source'   => $l->source,
                'link'     => '/leads',
            ];
        }

        return $events;
    }

    /**
     * Taken met een deadline-week als kalender-events (hele dag op de vrijdag
     * van die ISO-week — precies zoals de "Taken & agenda"-kalender ze toont).
     * Alleen voor de iCal-feed; het JSON-events-endpoint laat taken weg omdat
     * de admin-agenda die al client-side toevoegt.
     */
    private function gatherTasks(Carbon $from, Carbon $to): array
    {
        $events = [];

        $todos = Todo::whereNotNull('deadline_week')
            ->whereNotNull('deadline_year')
            ->get();

        foreach ($todos as $t) {
            // Vrijdag (ISO-dag 5) van de deadline-week.
            $date = Carbon::now()->setISODate((int) $t->deadline_year, (int) $t->deadline_week, 5)->startOfDay();
            if ($date->lt($from) || $date->gt($to)) {
                continue;
            }

            $events[] = [
                'id'       => 'task-' . $t->id,
                'type'     => 'task',
                'title'    => $t->title,
                'start'    => $date->toIso8601String(),
                'end'      => null,
                'all_day'  => true,
                'color'    => '#3b82f6',
                'notes'    => $t->description,
                'status'   => $t->status,
            ];
        }

        return $events;
    }

    // ── GET /admin/agenda/ical-url ─────────────────────────────────
    // Geeft de abonneer-URL's terug (webcal:// voor 1-tik-toevoegen op iOS/
    // macOS, plus de https-variant). Genereert het geheime token lui bij de
    // eerste keer.
    public function icalUrl(Request $request)
    {
        return response()->json($this->icalUrlsFor($this->ensureIcalToken(Auth::user())));
    }

    // ── POST /admin/agenda/ical-url/reset ──────────────────────────
    // Roteer het token: oude abonnementen (bv. op een verloren toestel) stoppen
    // dan met werken. De gebruiker moet daarna opnieuw abonneren.
    public function icalReset(Request $request)
    {
        $user = Auth::user();
        $user->agenda_ical_token = $this->freshIcalToken();
        $user->save();

        return response()->json($this->icalUrlsFor($user->agenda_ical_token));
    }

    // ── GET /agenda/feed/{token}.ics ───────────────────────────────
    // PUBLIEK (geen auth-header — de Agenda-app kan die niet meesturen). Het
    // token in de URL is het enige geheim. Alleen admins hebben een feed.
    public function ical(string $token)
    {
        $token = preg_replace('/\.ics$/', '', $token);
        $user  = User::where('agenda_ical_token', $token)->first();

        abort_if(! $user || ! $user->is_admin, 404);

        // Ruim venster zodat de abonnee zowel recente historie als komende
        // maanden ziet; iOS ververst periodiek (X-PUBLISHED-TTL hieronder).
        $from = Carbon::now()->subDays(60)->startOfDay();
        $to   = Carbon::now()->addDays(365)->endOfDay();

        // Agenda-events + taken-met-deadline (zoals de "Taken & agenda"-kalender).
        $events = array_merge($this->gatherEvents($from, $to), $this->gatherTasks($from, $to));
        $ics    = $this->buildIcs($events);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="milmap-agenda.ics"',
            'Cache-Control'       => 'no-cache, must-revalidate',
        ]);
    }

    /** Zorg dat de gebruiker een feed-token heeft en geef het terug. */
    private function ensureIcalToken(User $user): string
    {
        if (empty($user->agenda_ical_token)) {
            $user->agenda_ical_token = $this->freshIcalToken();
            $user->save();
        }

        return $user->agenda_ical_token;
    }

    private function freshIcalToken(): string
    {
        do {
            $token = Str::random(48);
        } while (User::where('agenda_ical_token', $token)->exists());

        return $token;
    }

    /** Bouw de webcal:// + https:// abonneer-URL's voor een token. */
    private function icalUrlsFor(string $token): array
    {
        $base    = rtrim(config('app.url', 'https://api.milmap.nl'), '/');
        $httpUrl = "{$base}/api/v1/agenda/feed/{$token}.ics";
        // webcal:// laat iOS/macOS 'm meteen als abonnement toevoegen.
        $webcal  = preg_replace('#^https?://#', 'webcal://', $httpUrl);

        return ['url' => $httpUrl, 'webcal' => $webcal];
    }

    /** Serialiseer events naar een RFC 5545 VCALENDAR (iCal). */
    private function buildIcs(array $events): string
    {
        $now   = Carbon::now('UTC');
        $stamp = $now->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MilMap//Admin Agenda//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:MilMap Agenda',
            'X-WR-CALDESC:Afspraken, geplande + verzonden campagnes en nieuwe leads',
            'X-WR-TIMEZONE:Europe/Amsterdam',
            // Hint voor clients hoe vaak te verversen (iOS respecteert dit).
            'REFRESH-INTERVAL;VALUE=DURATION:PT2H',
            'X-PUBLISHED-TTL:PT2H',
        ];

        foreach ($events as $e) {
            if (empty($e['start'])) {
                continue;
            }
            $start = Carbon::parse($e['start']);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $e['id'] . '@milmap.nl';
            $lines[] = 'DTSTAMP:' . $stamp;

            if (! empty($e['all_day'])) {
                // Hele dag: DATE-waarde; DTEND is exclusief (dag erna).
                $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
                $end     = ! empty($e['end']) ? Carbon::parse($e['end']) : $start->copy();
                $lines[] = 'DTEND;VALUE=DATE:' . $end->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:' . $start->copy()->utc()->format('Ymd\THis\Z');
                if (! empty($e['end'])) {
                    $lines[] = 'DTEND:' . Carbon::parse($e['end'])->utc()->format('Ymd\THis\Z');
                }
            }

            $lines[] = 'SUMMARY:' . $this->icsEscape($this->summaryFor($e));

            $desc = $this->descriptionFor($e);
            if ($desc !== '') {
                $lines[] = 'DESCRIPTION:' . $this->icsEscape($desc);
            }
            if (! empty($e['location'])) {
                $lines[] = 'LOCATION:' . $this->icsEscape($e['location']);
            }
            // Kleur (Apple/most clients tonen dit als event-kleur).
            if (! empty($e['color'])) {
                $lines[] = 'X-APPLE-CALENDAR-COLOR:' . $e['color'];
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545: CRLF + regels vouwen op 75 octetten.
        return implode("\r\n", array_map([$this, 'foldLine'], $lines)) . "\r\n";
    }

    /** Prefix het type zodat het in een gedeelde agenda direct herkenbaar is. */
    private function summaryFor(array $e): string
    {
        $prefix = [
            'appointment'        => '',
            'campaign_scheduled' => '📤 ',
            'campaign_sent'      => '✅ ',
            'lead'               => '👤 ',
            'task'               => '📋 ',
        ][$e['type']] ?? '';

        return $prefix . ($e['title'] ?: 'Agenda');
    }

    private function descriptionFor(array $e): string
    {
        $bits = [];
        if (! empty($e['notes'])) {
            $bits[] = $e['notes'];
        }
        $typeLabel = [
            'appointment'        => 'Afspraak',
            'campaign_scheduled' => 'Geplande campagne',
            'campaign_sent'      => 'Verzonden campagne',
            'lead'               => 'Nieuwe lead',
            'task'               => 'Taak (deadline)',
        ][$e['type']] ?? null;
        if ($typeLabel) {
            $bits[] = $typeLabel;
        }
        if (! empty($e['source'])) {
            $bits[] = 'Bron: ' . $e['source'];
        }

        return implode("\n", $bits);
    }

    /** Escape reservetekens per RFC 5545 (\\ ; , en newlines). */
    private function icsEscape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $text
        );
    }

    /** Vouw een regel op 75 octetten met een spatie-continuatie (RFC 5545). */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out    = '';
        $offset = 0;
        $len    = strlen($line);
        // Eerste stuk 75, vervolgstukken 74 (1 octet voor de leidende spatie).
        while ($offset < $len) {
            $chunk = $offset === 0 ? 75 : 74;
            $piece = substr($line, $offset, $chunk);
            $out  .= ($offset === 0 ? '' : "\r\n ") . $piece;
            $offset += $chunk;
        }

        return $out;
    }

    public function store(Request $request)
    {
        $data = $this->validateAppointment($request);
        $data['created_by'] = Auth::id();

        $appt = AgendaAppointment::create($data);

        return response()->json(['appointment' => $appt], 201);
    }

    public function update(Request $request, int $id)
    {
        $appt = AgendaAppointment::findOrFail($id);
        $appt->update($this->validateAppointment($request));

        return response()->json(['appointment' => $appt]);
    }

    public function destroy(int $id)
    {
        AgendaAppointment::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function validateAppointment(Request $request): array
    {
        return $request->validate([
            'title'     => ['required', 'string', 'max:160'],
            'notes'     => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day'   => ['boolean'],
            'color'     => ['nullable', 'string', 'max:20'],
            'location'  => ['nullable', 'string', 'max:190'],
        ]);
    }
}
