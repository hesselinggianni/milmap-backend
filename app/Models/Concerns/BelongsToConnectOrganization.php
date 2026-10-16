<?php
namespace App\Models\Concerns;
use App\Services\ConnectManagement;
trait BelongsToConnectOrganization {
    public static function bootBelongsToConnectOrganization(): void {
        static::addGlobalScope('connect_access', function ($query) {
            $user = auth('sanctum')->user();
            if (!$user || !\Illuminate\Support\Facades\Schema::hasTable('connect_members')) return;
            [$internal, $external] = app(ConnectManagement::class)->visibleOrganizationIds($user);
            $column = $query->getModel()->qualifyColumn('connect_organization_id');
            $query->where(fn ($q) => $q->whereNull($column)->orWhereIn($column, $internal)->orWhereIn($column, $external));
        });
        static::creating(function ($model) {
            // Ignore any submitted organisation id. Ownership derives from the
            // authenticated session (or owner on an internal/queue create).
            $user = auth('sanctum')->user() ?? \App\Models\User::find($model->owner_id);
            $context = $user ? app(ConnectManagement::class)->context($user) : ['managed' => false];
            if ($context['managed']) $model->connect_organization_id = $context['organization']['id'] ?? null;
            elseif (array_key_exists('connect_organization_id', $model->getAttributes())) $model->connect_organization_id = null;
        });
        static::updating(function ($model) {
            if ($model->isDirty('connect_organization_id')) throw new \LogicException('Organisation ownership cannot be changed.');
        });
    }
    public function connectOrganization() { return $this->belongsTo(\App\Models\ConnectOrganization::class, 'connect_organization_id'); }
}
