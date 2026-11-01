<?php

namespace App\Console\Commands;

use App\Models\KakoAppCheckin;
use App\Models\KakoAppParticipant;
use App\Models\KakoAppPosition;
use App\Models\RouteAssignment;
use App\Support\KakoAppRules;
use Illuminate\Console\Command;

/**
 * Bewaartermijn van KAKO-positiedata (docs/kako-app-modus.md, §9): ruwe posities en de
 * laatste-positie-cache worden `appMode.tracking.retentionDays` na het einde van het
 * venster gewist. De coördinaten die bij een check-in horen gaan mee uit de check-in
 * (afstand en uitkomst blijven staan als examenadministratie).
 */
class KakoPurgePositions extends Command
{
    protected $signature = 'kako:purge-positions {--dry-run : Alleen tellen, niets wissen}';

    protected $description = 'Wist KAKO-posities na de bewaartermijn van de opdracht';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $positions = 0;
        $checkins = 0;
        $participants = 0;

        // 7 dagen is de kortst mogelijke termijn: alles daarbinnen hoeft niet bekeken te worden.
        $query = KakoAppParticipant::whereNotNull('window_until')->where('window_until', '<', now()->subDays(7));
        $assignments = RouteAssignment::whereIn('id', (clone $query)->select('route_assignment_id'))->get()->keyBy('id');

        $query->orderBy('id')->chunkById(200, function ($rows) use ($assignments, $dry, &$positions, &$checkins, &$participants) {
            foreach ($rows as $p) {
                $days = $assignments->has($p->route_assignment_id)
                    ? KakoAppRules::config($assignments[$p->route_assignment_id]->settings ?? [])['tracking']['retention_days']
                    : 7;
                if ($p->window_until->gt(now()->subDays($days))) {
                    continue;
                }

                $pos = KakoAppPosition::where('participant_id', $p->id);
                $chk = KakoAppCheckin::where('participant_id', $p->id)->where(fn ($q) => $q->whereNotNull('lat')->orWhereNotNull('lon'));
                $nPos = (clone $pos)->count();
                $nChk = (clone $chk)->count();
                $positions += $nPos;
                $checkins += $nChk;
                if (! $dry) {
                    $pos->delete();
                    $chk->update(['lat' => null, 'lon' => null, 'accuracy' => null]);
                    $p->update(['last_lat' => null, 'last_lon' => null, 'last_accuracy' => null, 'last_position_at' => null]);
                }
                if ($nPos + $nChk > 0) {
                    $participants++;
                }
            }
        });

        $this->info(sprintf('%s: %d positie(s) en %d check-in-positie(s) van %d deelnemer(s).', $dry ? 'Zou wissen' : 'Gewist', $positions, $checkins, $participants));

        return self::SUCCESS;
    }
}
