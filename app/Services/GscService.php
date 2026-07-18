<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Google Search Console-koppeling. Authenticeert met een service-account
 * (JWT → access-token, scope webmasters.readonly) en bevraagt de Search
 * Analytics API. Credentials + property-URL staan in Settings (instelbaar
 * via het admin-scherm); het service-account moet als gebruiker aan de
 * Search Console-property zijn toegevoegd.
 */
class GscService
{
    private function creds(): ?array
    {
        $raw = Setting::get('gsc_credentials', '');
        if (! $raw) return null;
        $data = is_array($raw) ? $raw : json_decode($raw, true);
        return (is_array($data) && isset($data['client_email'], $data['private_key'])) ? $data : null;
    }

    public function siteUrl(): string
    {
        return (string) Setting::get('gsc_site_url', '');
    }

    public function serviceAccountEmail(): ?string
    {
        return $this->creds()['client_email'] ?? null;
    }

    public function isConfigured(): bool
    {
        return $this->creds() !== null && $this->siteUrl() !== '';
    }

    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function accessToken(): ?string
    {
        $c = $this->creds();
        if (! $c) return null;

        $now = time();
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = $this->b64url(json_encode([
            'iss'   => $c['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $sig = '';
        if (! openssl_sign("$header.$claim", $sig, $c['private_key'], 'sha256WithRSAEncryption')) {
            return null;
        }
        $jwt = "$header.$claim." . $this->b64url($sig);

        $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        return $resp->json('access_token');
    }

    /**
     * Ruwe Search Analytics-query. $dimensions bv. ['query'] of ['page'] (leeg =
     * totalen). Geeft de 'rows' terug (of []).
     */
    public function query(array $dimensions, int $days = 28, int $rowLimit = 25): array
    {
        $token = $this->accessToken();
        $site  = $this->siteUrl();
        if (! $token || ! $site) return [];

        $resp = Http::withToken($token)->post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query',
            [
                'startDate'  => now()->subDays($days)->toDateString(),
                'endDate'    => now()->toDateString(),
                'dimensions' => $dimensions,
                'rowLimit'   => $rowLimit,
            ]
        );

        return $resp->json('rows', []) ?: [];
    }

    public function totals(int $days = 28): array
    {
        $r = $this->query([], $days, 1)[0] ?? null;

        return [
            'clicks'      => (int) round($r['clicks'] ?? 0),
            'impressions' => (int) round($r['impressions'] ?? 0),
            'ctr'         => (float) ($r['ctr'] ?? 0),
            'position'    => (float) ($r['position'] ?? 0),
        ];
    }

    public function topRows(string $dimension, int $days = 28, int $rowLimit = 25): array
    {
        return array_map(fn ($r) => [
            'key'         => $r['keys'][0] ?? '',
            'clicks'      => (int) round($r['clicks'] ?? 0),
            'impressions' => (int) round($r['impressions'] ?? 0),
            'ctr'         => (float) ($r['ctr'] ?? 0),
            'position'    => (float) ($r['position'] ?? 0),
        ], $this->query([$dimension], $days, $rowLimit));
    }

    /** Dagelijkse tijdreeks (voor de kliks/vertoningen-grafiek), oplopend op datum. */
    public function byDate(int $days = 28): array
    {
        $rows = array_map(fn ($r) => [
            'date'        => $r['keys'][0] ?? '',
            'clicks'      => (int) round($r['clicks'] ?? 0),
            'impressions' => (int) round($r['impressions'] ?? 0),
            'ctr'         => (float) ($r['ctr'] ?? 0),
            'position'    => (float) ($r['position'] ?? 0),
        ], $this->query(['date'], $days, max(100, $days + 5)));

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }
}
