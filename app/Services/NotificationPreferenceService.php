<?php

namespace App\Services;

use App\Models\MailPushOptin;

/**
 * Eén plek voor "wil dit contact deze categorie via dit kanaal ontvangen?".
 * - E-mail: opt-out-model (default AAN) via mail_opt_outs (e-mail-gekeyd, ook leads).
 * - Push:   opt-in-model (default UIT) via mail_push_optins (alleen accounts).
 */
class NotificationPreferenceService
{
    /** Wil dit e-mailadres deze categorie per e-mail? (default aan, tenzij afgemeld). */
    public static function wantsEmail(string $email, ?int $categoryId): bool
    {
        return ! MailOptOutService::isOptedOut($email, $categoryId);
    }

    /** Heeft deze gebruiker push aangezet voor deze categorie? (default uit; leads: nooit). */
    public static function wantsPush(?int $userId, ?int $categoryId): bool
    {
        if (! $userId || ! $categoryId) {
            return false;
        }

        return MailPushOptin::where('user_id', $userId)->where('category_id', $categoryId)->exists();
    }

    public static function setPush(int $userId, int $categoryId, bool $enabled): void
    {
        if ($enabled) {
            MailPushOptin::firstOrCreate(['user_id' => $userId, 'category_id' => $categoryId]);
        } else {
            MailPushOptin::where('user_id', $userId)->where('category_id', $categoryId)->delete();
        }
    }
}
