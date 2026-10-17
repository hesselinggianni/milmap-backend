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
            // A route is part of its parent map, even when an external
            // collaborator creates it from a personal or another tenant's account.
            if ($model instanceof \App\Models\RouteMap) {
                $model->connect_organization_id = \App\Models\Map::findOrFail($model->map_id)->connect_organization_id;
                return;
            }
            // Ignore any submitted organisation id. Ownership derives from the
            // authenticated session (or owner on an internal/queue create).
            $user = auth('sanctum')->user() ?? \App\Models\User::find($model->owner_id);
            $context = $user ? app(ConnectManagement::class)->context($user) : ['managed' => false];
            if ($context['managed']) $model->connect_organization_id = $context['organization']['id'] ?? null;
            elseif (array_key_exists('connect_organization_id', $model->getAttributes())) $model->connect_organization_id = null;
        });
        static::updating(function ($model) {
            if ($model->isDirty('connect_organization_id')) throw new \LogicException('Organisation ownership cannot be changed.');
            if ($model instanceof \App\Models\RouteMap && $model->isDirty('map_id')) {
                $parent = \App\Models\Map::findOrFail($model->map_id);
                if ((int) $parent->connect_organization_id !== (int) $model->connect_organization_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['map_id' => 'Een organisatieroute kan niet naar een kaart van een andere organisatie worden verplaatst.']);
                }
            }
        });
    }
    public function connectOrganization() { return $this->belongsTo(\App\Models\ConnectOrganization::class, 'connect_organization_id'); }
}
