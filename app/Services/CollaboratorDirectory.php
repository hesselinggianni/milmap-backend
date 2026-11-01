<?php

namespace App\Services;

use App\Models\ConnectMember;
use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Zoeken naar mensen om aan een missie toe te voegen ("Gebruiker"-tab van de
 * deel-dialoog). Bewust géén doorzoekbare gebruikersdatabase: wat een zoeker te
 * zien krijgt hangt af van de relatie met die persoon.
 *
 *  - contact       Eerdere samenwerkers (zie InvitationService::knownContactIds).
 *                  Zoeken op naam, e-mail of gebruikersnaam; e-mail zichtbaar.
 *  - organization  Actieve leden van dezelfde Connect-organisatie (school/eenheid),
 *                  alleen als het beleid intern delen toestaat (`share_internal`).
 *                  Zoeken op naam, e-mail of gebruikersnaam; e-mail zichtbaar.
 *                  Apparaataccounts (gedeelde iPads) zijn nooit vindbaar.
 *  - username      Iedereen met een openbaar profiel, maar uitsluitend bij een
 *                  exacte treffer op de gebruikersnaam (de handle). Geen e-mail en
 *                  geen echte naam in het antwoord; toevoegen gaat via een
 *                  uitnodiging die de ander eerst moet accepteren.
 *
 * Een e-mailadres van een vreemde levert bewust niets op: dat loopt via de
 * bestaande e-mailuitnodiging, zodat zoeken geen manier is om te controleren
 * of een adres een account heeft.
 */
class CollaboratorDirectory
{
    public const MIN_QUERY = 2;
    public const MIN_HANDLE = 3;
    public const LIMIT = 20;

    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_ORGANIZATION = 'organization';
    public const SOURCE_USERNAME = 'username';

    /**
     * @return array<int, array{id:int,name:string,email:?string,handle:?string,avatar_url:?string,source:string,direct:bool}>
     */
    public function search(User $actor, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        // Elk woord moet ergens voorkomen (naam, e-mail of gebruikersnaam): "jan jansen" vindt Jan Jansen.
        $tokens = array_slice(preg_split('/\s+/u', mb_strtolower(ltrim($query, '@'))), 0, 4);
        $likes = array_map(fn ($t) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t) . '%', $tokens);

        $results = collect();
        $results = $results->merge($this->searchScope($actor, $this->contactIds($actor), $likes, self::SOURCE_CONTACT));
        $colleagues = $this->colleagueQuery($actor);
        if ($colleagues) {
            $results = $results->merge($this->searchScope($actor, $colleagues, $likes, self::SOURCE_ORGANIZATION, $results->pluck('id')->all()));
        }

        // Exacte gebruikersnaam van een vreemde: alleen vindbaar via een openbaar profiel.
        $handle = PublicProfile::normalizeHandle(ltrim($query, '@'));
        if (preg_match('/^[a-z0-9_]{' . self::MIN_HANDLE . ',30}$/', $handle)) {
            $profile = PublicProfile::visible()->where('handle', $handle)->with('user')->first();
            if ($profile && $profile->user
                && (int) $profile->user_id !== (int) $actor->id
                && ! $profile->user->connect_device_account
                && ! $results->contains('id', (int) $profile->user_id)) {
                $results->push([
                    'id' => (int) $profile->user_id,
                    'name' => $profile->publicName(),
                    'email' => null,
                    'handle' => $profile->handle,
                    'avatar_url' => $profile->user->avatar_url,
                    'source' => self::SOURCE_USERNAME,
                    'direct' => false,
                ]);
            }
        }

        return $results->take(self::LIMIT)->values()->all();
    }

    /**
     * Mag $targetId direct (zonder uitnodiging die de ander accepteert) worden
     * toegevoegd? Alleen eerdere samenwerkers en leden van de eigen Connect-organisatie.
     */
    public function directScope(User $actor, int $targetId): ?string
    {
        if ($targetId === (int) $actor->id) {
            return null;
        }
        if (in_array($targetId, $this->contactIds($actor), true)) {
            return self::SOURCE_CONTACT;
        }
        if (($colleagues = $this->colleagueQuery($actor)) && $colleagues->where('user_id', $targetId)->exists()) {
            return self::SOURCE_ORGANIZATION;
        }

        return null;
    }

    /** Heeft deze gebruiker een openbaar profiel (en is dus op gebruikersnaam vindbaar)? */
    public function isDiscoverable(int $userId): bool
    {
        return PublicProfile::visible()->where('user_id', $userId)
            ->whereHas('user', fn ($q) => $q->where('connect_device_account', false))->exists();
    }

    /** @return int[] */
    private function contactIds(User $actor): array
    {
        return InvitationService::knownContactIds($actor->id);
    }

    /**
     * Actieve leden van de Connect-organisatie van $actor, als query (een school
     * kan duizenden leden hebben). null zonder organisatie, zonder actieve licentie
     * of als het beleid intern delen niet toestaat.
     */
    private function colleagueQuery(User $actor): ?Builder
    {
        $management = app(ConnectManagement::class);
        $context = $management->context($actor);
        $orgId = $context['organization']['id'] ?? null;
        if (! ($context['managed'] ?? false) || ! ($context['active'] ?? false) || ! $orgId
            || ! $management->allows($actor, 'share_internal')) {
            return null;
        }

        return ConnectMember::query()
            ->select('user_id')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $actor->id)
            ->excludingDeviceAccounts();
    }

    /**
     * @param  int[]|Builder  $scope  Toegestane gebruikers: lijst met id's of een subquery op user_id.
     * @param  string[]  $likes  LIKE-patronen (ESCAPE '!'), allemaal van toepassing.
     * @param  int[]  $skip  Al opgenomen id's (een contact die ook collega is komt één keer voor).
     */
    private function searchScope(User $actor, array|Builder $scope, array $likes, string $source, array $skip = []): Collection
    {
        if (is_array($scope) && ! $scope) {
            return collect();
        }

        $users = User::whereIn('id', $scope)
            ->where('id', '!=', $actor->id)
            ->where('connect_device_account', false)
            ->when($skip, fn ($q) => $q->whereNotIn('id', $skip))
            ->where(function ($q) use ($likes) {
                foreach ($likes as $like) {
                    $q->where(function ($w) use ($like) {
                        $w->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$like])
                            ->orWhereIn('id', PublicProfile::visible()->select('user_id')->whereRaw("handle LIKE ? ESCAPE '!'", [$like]));
                    });
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'avatar_path', 'email']);

        $handles = PublicProfile::visible()->whereIn('user_id', $users->pluck('id'))->pluck('handle', 'user_id');

        return $users->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => $u->full_name,
            'email' => $u->email,
            'handle' => $handles[$u->id] ?? null,
            'avatar_url' => $u->avatar_url,
            'source' => $source,
            'direct' => true,
        ]);
    }
}
