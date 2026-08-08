<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Voert geplande accountverwijderingen uit zodra de bedenktijd voorbij is.
 *
 * De verwijdering zelf is één DELETE op de user-rij: de user_id-koppelingen
 * staan op ON DELETE CASCADE, dus kaarten, missies, waypoints, activiteiten en
 * berichten gaan mee. Draait dagelijks (zie routes/console.php).
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge';
    protected $description = 'Verwijder accounts waarvan de 90-daagse bedenktijd is verstreken';

    public function handle(): int
    {
        $rijp = User::whereNotNull('deletion_purge_at')
            ->where('deletion_purge_at', '<=', now())
            ->get();

        if ($rijp->isEmpty()) {
            $this->info('Niets te verwijderen.');

            return self::SUCCESS;
        }

        foreach ($rijp as $user) {
            try {
                $id = $user->id;
                $user->tokens()->delete();
                $user->delete();
                Log::info('[account] definitief verwijderd na bedenktijd', ['user_id' => $id]);
                $this->line("verwijderd: #{$id}");
            } catch (\Throwable $e) {
                // Eén mislukking mag de rest niet blokkeren; morgen weer.
                Log::error('[account] definitieve verwijdering mislukt', [
                    'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
