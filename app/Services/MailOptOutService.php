<?php

namespace App\Services;

use App\Models\MailOptOut;

/**
 * Afmeldingen (opt-outs), e-mail-gekeyd zodat gebruikers én leads in dezelfde tabel
 * zitten. category_id = null betekent globaal (alle marketingmail). Alle schrijf-acties
 * zijn idempotent (NULL-bewust) omdat de DB om de NULL-unique-caveat geen unique-index
 * heeft op (email, category_id).
 */
class MailOptOutService
{
    /**
     * Is dit e-mailadres afgemeld voor de gegeven categorie? Een globale afmelding
     * (category_id NULL) telt altijd mee.
     */
    public static function isOptedOut(string $email, ?int $categoryId): bool
    {
        $email = mb_strtolower(trim($email));

        return MailOptOut::where('email', $email)
            ->where(function ($q) use ($categoryId) {
                $q->whereNull('category_id'); // globaal afgemeld
                if ($categoryId !== null) {
                    $q->orWhere('category_id', $categoryId);
                }
            })
            ->exists();
    }

    /** Meld af voor één categorie (of globaal als $categoryId null is). Idempotent. */
    public static function optOut(string $email, ?int $categoryId, string $source = 'unsubscribe_link'): MailOptOut
    {
        $email = mb_strtolower(trim($email));

        $existing = static::scope($email, $categoryId)->first();
        if ($existing) {
            return $existing;
        }

        return MailOptOut::create([
            'email'           => $email,
            'category_id'     => $categoryId,
            'unsubscribed_at' => now(),
            'source'          => $source,
        ]);
    }

    /** Meld weer aan (verwijder de opt-out-rij) voor één categorie (of globaal). */
    public static function optIn(string $email, ?int $categoryId): void
    {
        $email = mb_strtolower(trim($email));
        static::scope($email, $categoryId)->delete();
    }

    private static function scope(string $email, ?int $categoryId)
    {
        $q = MailOptOut::where('email', $email);
        $categoryId === null ? $q->whereNull('category_id') : $q->where('category_id', $categoryId);

        return $q;
    }
}
