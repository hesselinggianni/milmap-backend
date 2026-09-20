<?php

namespace App\Http\Traits;

use App\Models\Map;
use Illuminate\Support\Facades\Auth;

/**
 * Toegangscheck voor een kaart: eigenaar, of collaborator met status
 * 'accepted'. Voor schrijfacties telt een 'viewer' niet mee.
 *
 * Stond eerder privé in MapWaypointController; nu gedeeld met
 * WorkspaceController. Bewust één implementatie — een tweede kopie van deze
 * check die uit de pas gaat lopen is een security-risico.
 */
trait AuthorizesMapAccess
{
    protected function authorizeMapAccess(Map $map, bool $editorRequired = false): bool
    {
        $user = Auth::user();
        return $user && \Illuminate\Support\Facades\Gate::forUser($user)
            ->allows($editorRequired ? 'update' : 'view', $map);
    }
}
