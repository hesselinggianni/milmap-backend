<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Aankomsttaken ("Bij het pick-up point: bel de baas") op een kaartobject.
 *
 * Een taak is { id, title, required, done, done_at, done_by, done_by_name }.
 * Wie een taak afvinkte en wanneer zet altijd de server, nooit de client.
 *
 * Eerst voor gemarkeerde gebieden (ReportController). MapWaypointController
 * heeft nog een eigen kopie van deze logica; die kan hier ook op overstappen
 * zodra het waypoint-takenwerk is gecommit.
 */
trait ResolvesArrivalTasks
{
    protected const MAX_ARRIVAL_TASKS = 30;

    /**
     * Valideer-regels voor de taken. done_at/done_by/done_by_name komen bewust
     * NIET uit het verzoek: anders kan een client een ander als afvinker opgeven.
     */
    protected function arrivalTaskRules(): array
    {
        return [
            'tasks'            => 'nullable|array|max:' . self::MAX_ARRIVAL_TASKS,
            'tasks.*.id'       => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Leeg mag: lege titels vallen weg in resolveArrivalTasks(), zodat
            // een half ingetypte regel het opslaan niet blokkeert.
            'tasks.*.title'    => 'nullable|string|max:200',
            'tasks.*.required' => 'nullable|boolean',
            'tasks.*.done'     => 'nullable|boolean',
            'task_radius'      => 'nullable|integer|between:10,1000',
        ];
    }

    /**
     * Maak van de door de client gestuurde taken de opgeslagen lijst: alleen de
     * bekende velden, lege titels eruit, dubbele id's eruit, en afvink-gegevens
     * door de server gezet. Een taak die al afgevinkt was en dat blijft, behoudt
     * zijn oorspronkelijke tijdstip en afvinker.
     */
    protected function resolveArrivalTasks(?array $existing, array $incoming): array
    {
        $before = collect($existing ?: [])->keyBy('id');
        $now    = now()->toIso8601String();
        $actor  = Auth::user();
        $name   = $actor ? mb_substr((string) ($actor->first_name ?: ($actor->name ?? '')), 0, 80) : '';

        $out  = [];
        $seen = [];
        foreach (array_slice($incoming, 0, self::MAX_ARRIVAL_TASKS) as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            $id    = (string) ($t['id'] ?? '');
            if ($title === '' || $id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $prev    = $before->get($id);
            $done    = (bool) ($t['done'] ?? false);
            $wasDone = (bool) ($prev['done'] ?? false);
            $keep    = $done && $wasDone;

            $out[] = [
                'id'           => $id,
                'title'        => mb_substr($title, 0, 200),
                'required'     => (bool) ($t['required'] ?? false),
                'done'         => $done,
                'done_at'      => $done ? ($keep ? ($prev['done_at'] ?? $now) : $now) : null,
                'done_by'      => $done ? ($keep ? ($prev['done_by'] ?? null) : $actor?->id) : null,
                'done_by_name' => $done ? ($keep ? ($prev['done_by_name'] ?? null) : ($name !== '' ? $name : null)) : null,
            ];
        }
        return $out;
    }

    /**
     * Titels voor de wijzigingslog. Afvinken hoort daar niet in: dat is een
     * eigen regel (task_done / task_reopened).
     *
     * @return list<string>
     */
    protected function arrivalTaskTitles(?array $tasks): array
    {
        return collect($tasks ?: [])->map(fn ($t) => (string) ($t['title'] ?? ''))->values()->all();
    }
}
