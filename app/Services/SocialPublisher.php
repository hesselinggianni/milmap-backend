<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publiceert een SocialPost naar een specifiek platform. Pinterest gaat via de
 * echte v5-API (zodra er een SocialAccount met token + board is); Instagram en
 * Dribbble leveren voorlopig een "manual-assist"-payload op (caption + publieke
 * beeld-URL + composer-link) omdat die automatisch posten pas kan na resp. Meta
 * app-review en een Dribbble OAuth-app.
 */
class SocialPublisher
{
    /** Publiceer naar één platform en geef het status-record terug. */
    public function publish(SocialPost $post, string $platform): array
    {
        try {
            return match ($platform) {
                'pinterest' => $this->pinterest($post),
                'instagram' => $this->manual($post, 'instagram', 'https://www.instagram.com/'),
                'dribbble'  => $this->dribbble($post),
                default     => $this->fail('Onbekend platform.'),
            };
        } catch (\Throwable $e) {
            Log::error('SocialPublisher faalde', ['platform' => $platform, 'msg' => $e->getMessage()]);
            return $this->fail($e->getMessage());
        }
    }

    // ── Pinterest (echte API v5) ─────────────────────────────────────
    private function pinterest(SocialPost $post): array
    {
        $acct = SocialAccount::where('platform', 'pinterest')->first();
        if (! $acct || ! $acct->isConnected()) {
            // Geen credentials → val terug op manual-assist zodat de post niet blokkeert.
            return $this->manual($post, 'pinterest', 'https://www.pinterest.com/pin-builder/');
        }

        $imageUrl = $post->imageUrl();
        if (! $imageUrl) {
            return $this->fail('Geen beeld aan de post gekoppeld.');
        }

        $res = Http::withToken($acct->access_token)
            ->acceptJson()
            ->post('https://api.pinterest.com/v5/pins', array_filter([
                'board_id'     => $acct->board_id,
                'title'        => $post->title ? mb_substr($post->title, 0, 100) : null,
                'description'  => $post->description ? mb_substr($post->description, 0, 500) : null,
                'link'         => $post->link,
                'media_source' => [
                    'source_type' => 'image_url',
                    'url'         => $imageUrl,
                ],
            ], fn ($v) => $v !== null));

        if ($res->successful()) {
            $id = $res->json('id');
            return [
                'state'        => 'published',
                'url'          => $id ? "https://www.pinterest.com/pin/{$id}/" : null,
                'published_at' => now()->toIso8601String(),
            ];
        }

        return $this->fail('Pinterest API: ' . $res->status() . ' ' . $res->body());
    }

    // ── Dribbble (manual-assist met eigen upload-link) ───────────────
    private function dribbble(SocialPost $post): array
    {
        return $this->manual($post, 'dribbble', 'https://dribbble.com/uploads/new');
    }

    // ── Manual-assist: caption + publieke beeld-URL + composer-link ──
    private function manual(SocialPost $post, string $platform, string $composerUrl): array
    {
        $captionParts = array_filter([$post->title, $post->description, $post->link]);
        return [
            'state'        => 'manual',
            'caption'      => implode("\n\n", $captionParts),
            'image_url'    => $post->imageUrl(),
            'composer_url' => $composerUrl,
            'note'         => $this->manualNote($platform),
        ];
    }

    private function manualNote(string $platform): string
    {
        return match ($platform) {
            'pinterest' => 'Pinterest is nog niet gekoppeld — caption en beeld staan klaar om handmatig te plaatsen.',
            'instagram' => 'Instagram vereist Meta app-review voor auto-posten; plaats voorlopig handmatig via de app/Creator Studio.',
            'dribbble'  => 'Dribbble vereist een eigen OAuth-app; open de upload en plak de caption.',
            default     => 'Plaats handmatig; caption en beeld staan klaar.',
        };
    }

    private function fail(string $error): array
    {
        return ['state' => 'failed', 'error' => $error];
    }
}
