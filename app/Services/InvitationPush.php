<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Pushmelding (ook met gesloten app) zodra een bestaand account wordt
 * uitgenodigd voor een missie of kaart. Tikken op de melding opent
 * /invite/{token} in de app (zie MilMap-Frontend/src/services/pushLogout.js).
 * Nieuwe gebruikers hebben nog geen toestel; die krijgen alleen de e-mail.
 *
 * Bewust zonder e-mailadres in de tekst: de melding staat op het vergrendelscherm.
 */
class InvitationPush
{
    /** @return array{title:string, body:string, data:array<string,string>} */
    public static function message(Invitation $invite, User $recipient): array
    {
        $locale = in_array($recipient->language, ['nl', 'en'], true) ? $recipient->language : 'en';
        $type   = (string) $invite->resourceType();
        $types  = trans('push.type', [], $locale);
        $inviter = $invite->inviter ?? $invite->inviter()->first();

        return [
            'title' => trans('push.invitation_title', [], $locale),
            'body'  => trans('push.invitation_body', [
                'name'  => trim((string) ($inviter?->full_name ?? '')) ?: 'MilMap',
                'type'  => $types[$type] ?? $types['default'],
                'title' => (string) ($invite->invitable?->title ?? $invite->invitable?->name ?? ''),
            ], $locale),
            'data'  => ['action' => 'invitation', 'route' => '/invite/' . $invite->token],
        ];
    }

    /** Faalt nooit hard: een mislukte push mag het uitnodigen niet breken. */
    public static function send(Invitation $invite, ?User $recipient): void
    {
        try {
            if (! $recipient || $recipient->isArchived() || $recipient->isBlocked()) {
                return;
            }
            $m = self::message($invite, $recipient);
            PushSender::alertToUser((int) $recipient->id, $m['title'], $m['body'], $m['data']);
        } catch (\Throwable $e) {
            Log::warning('[push] uitnodigingsmelding mislukt: ' . $e->getMessage());
        }
    }
}
