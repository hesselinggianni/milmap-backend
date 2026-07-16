<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GscService;
use App\Services\SeoTaskService;
use Illuminate\Http\Request;

/**
 * Admin-koppeling met Google Search Console: toont zoekprestaties en genereert
 * SEO-taken die Claude kan verwerken.
 */
class AdminSeoInsightsController extends Controller
{
    public function __construct(private GscService $gsc) {}

    /** GET /api/v1/admin/seo/gsc/summary */
    public function summary(Request $request)
    {
        $days = (int) $request->query('days', 28);
        $days = max(7, min(90, $days));

        if (! $this->gsc->isConfigured()) {
            return response()->json([
                'configured'  => false,
                'siteUrl'     => $this->gsc->siteUrl(),
                'serviceAccount' => $this->gsc->serviceAccountEmail(),
            ]);
        }

        return response()->json([
            'configured'     => true,
            'siteUrl'        => $this->gsc->siteUrl(),
            'serviceAccount' => $this->gsc->serviceAccountEmail(),
            'days'           => $days,
            'totals'         => $this->gsc->totals($days),
            'topQueries'     => $this->gsc->topRows('query', $days, 25),
            'topPages'       => $this->gsc->topRows('page', $days, 25),
        ]);
    }

    /** POST /api/v1/admin/seo/gsc/config — property-URL + service-account JSON. */
    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'siteUrl'     => 'required|string|max:255',
            'credentials' => 'nullable|string',
        ]);

        Setting::put('gsc_site_url', trim($data['siteUrl']));

        if (! empty($data['credentials'])) {
            $json = json_decode($data['credentials'], true);
            if (! is_array($json) || ! isset($json['client_email'], $json['private_key'])) {
                return response()->json(['message' => 'Ongeldige service-account JSON (client_email/private_key ontbreekt).'], 422);
            }
            Setting::put('gsc_credentials', $json);
        }

        return response()->json([
            'configured'     => $this->gsc->isConfigured(),
            'serviceAccount' => $this->gsc->serviceAccountEmail(),
            'siteUrl'        => $this->gsc->siteUrl(),
        ]);
    }

    /** POST /api/v1/admin/seo/gsc/generate-tasks */
    public function generateTasks(SeoTaskService $seo)
    {
        return response()->json($seo->generate());
    }
}
