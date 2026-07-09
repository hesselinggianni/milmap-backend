<?php

namespace App\Http\Controllers;

use App\Models\AgendaAppointment;
use App\Models\Lead;
use App\Models\MailCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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

        return response()->json(['events' => $events]);
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
