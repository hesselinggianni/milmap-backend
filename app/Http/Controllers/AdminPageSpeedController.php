<?php

namespace App\Http\Controllers;

use App\Services\PageSpeedService;
use App\Services\PageSpeedTaskService;
use Illuminate\Http\Request;

/**
 * Admin-koppeling met Google PageSpeed Insights: toont de laatste Lighthouse-
 * scores per te monitoren URL (mobiel + desktop), meet op verzoek opnieuw en
 * genereert prestatie-taken die Claude kan oppakken.
 */
class AdminPageSpeedController extends Controller
{
    public function __construct(private PageSpeedService $ps) {}

    /** GET /api/v1/admin/pagespeed/summary */
    public function summary()
    {
        // Verrijk elke laatste meting met de performance-trend (score-historie)
        // zodat de UI in één call zowel de scores als de grafiek kan tonen.
        $reports = array_map(function (array $r) {
            $r['trend'] = array_map(
                fn ($h) => ['score' => $h['performance'], 'at' => $h['measuredAt']],
                $this->ps->history($r['url'], $r['strategy'], 30)
            );
            return $r;
        }, $this->ps->latest());

        return response()->json([
            'urls'    => $this->ps->urls(),
            'hasKey'  => $this->ps->apiKey() !== null,
            'reports' => $reports,
        ]);
    }

    /** GET /api/v1/admin/pagespeed/history?url=&strategy= */
    public function history(Request $request)
    {
        $data = $request->validate([
            'url'      => 'required|string',
            'strategy' => 'required|in:mobile,desktop',
        ]);

        return response()->json([
            'history' => $this->ps->history($data['url'], $data['strategy']),
        ]);
    }

    /** POST /api/v1/admin/pagespeed/config — te monitoren URL's + optionele API-sleutel. */
    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'urls'   => 'required|array|min:1',
            'urls.*' => 'string|max:255',
            'apiKey' => 'nullable|string|max:255',
        ]);

        $this->ps->saveUrls($data['urls']);
        if (array_key_exists('apiKey', $data) && $data['apiKey'] !== null) {
            $this->ps->saveApiKey($data['apiKey']);
        }

        return response()->json([
            'urls'   => $this->ps->urls(),
            'hasKey' => $this->ps->apiKey() !== null,
        ]);
    }

    /** POST /api/v1/admin/pagespeed/run — meet alle URL's opnieuw (kan even duren). */
    public function run()
    {
        return response()->json($this->ps->run());
    }

    /** POST /api/v1/admin/pagespeed/generate-tasks */
    public function generateTasks(PageSpeedTaskService $tasks)
    {
        return response()->json($tasks->generate());
    }
}
