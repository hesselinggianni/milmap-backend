<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Archiveert accounts die na 90 dagen nog steeds hun e-mailadres niet hebben
 * bevestigd (en geen actief abonnement hebben). Een gearchiveerd account kan niet
 * meer inloggen; het e-mailadres komt vrij om opnieuw te registreren (zie
 * RegisterController). Draait dagelijks via de scheduler.
 */
class ArchiveUnverifiedUsers extends Command
{
    protected $signature = 'users:archive-unverified {--dry-run : Toon alleen wat er zou gebeuren}';

    protected $description = 'Archiveer accounts die na 90 dagen niet zijn geverifieerd (en niet betalen).';

    public function handle(): int
    {
        $cutoff  = now()->subDays(User::VERIFICATION_ARCHIVE_DAYS);
        $dryRun  = (bool) $this->option('dry-run');

        $candidates = User::query()
            ->whereNull('email_verified_at')
            ->whereNull('archived_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $archived = 0;

        foreach ($candidates as $user) {
            // Betalende accounts overslaan — een abonnement geldt als echtheidsbewijs.
            if ($user->subscribed()) {
                continue;
            }

            if ($dryRun) {
                $this->line("zou archiveren: #{$user->id} {$user->email} (aangemaakt {$user->created_at})");
                $archived++;
                continue;
            }

            $user->forceFill(['archived_at' => now()])->save();
            // Lopende sessies/tokens intrekken zodat het account direct geblokkeerd is.
            $user->tokens()->delete();
            $archived++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Gearchiveerd: {$archived} ongeverifieerd account(s) ouder dan " . User::VERIFICATION_ARCHIVE_DAYS . ' dagen.');

        return self::SUCCESS;
    }
}
