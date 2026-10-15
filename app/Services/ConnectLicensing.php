<?php
namespace App\Services;

use App\Models\{ConnectOrganization, ConnectLicense};
use Illuminate\Support\Facades\DB;

class ConnectLicensing
{
    /** Caller must hold the organization lock while creating the baseline or adding capacity. */
    public function preserveExisting(ConnectOrganization $org): void
    {
        if ($org->licenses()->exists()) return;
        $attributes = $org->getAttributes();
        if (empty($attributes['starts_at']) || empty($attributes['ends_at'])) return;
        $org->licenses()->create([
            'name' => 'Enterprise-licentie', 'seat_count' => $attributes['seat_limit'],
            'monthly_price_cents' => $attributes['monthly_price_cents'] ?? 0, 'currency' => $attributes['currency'] ?? 'EUR',
            'starts_at' => $attributes['starts_at'], 'ends_at' => $attributes['ends_at'], 'source_reference' => 'legacy:'.$org->id,
        ]);
    }

    public function add(ConnectOrganization $organization, array $attributes): ConnectLicense
    {
        return DB::transaction(function () use ($organization, $attributes) {
            $org = ConnectOrganization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $this->preserveExisting($org);
            if (!empty($attributes['source_reference'])) {
                $existing = $org->licenses()->where('source_reference', $attributes['source_reference'])->first();
                if ($existing) return $existing;
            }
            return $org->licenses()->create($attributes);
        });
    }
}
