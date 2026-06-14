<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\MailAccount;
use App\Services\MailboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detecteer nieuwe INBOX-mail per geconfigureerde inbox en lever een lokale
 * notificatie af in de admin-app (via de AppNotification-feed). Bedoeld om elke
 * minuut te draaien (zie Console\Kernel).
 *
 * Mail wordt niet server-side opgeslagen — we pollen IMAP live en houden per
 * inbox de hoogst geziene UID bij (mail_accounts.last_seen_uid). De eerste run
 * zet enkel de baseline (geen notificaties over bestaande mail); daarna levert
 * elk bericht met een hogere UID één notificatie op voor de inbox-eigenaar.
 */
class NotifyNewMail extends Command
{
    protected $signature = 'mail:notify-new {--per=30 : Hoeveel recente berichten per inbox te scannen}';

    protected $description = 'Notificeer de admin-app bij nieuwe INBOX-mail per inbox';

    public function handle(MailboxService $mailbox): int
    {
        $perPage = max(1, (int) $this->option('per'));
        $accounts = MailAccount::where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->info('Geen actieve mailaccounts — niets te doen.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            try {
                $this->scanAccount($mailbox, $account, $perPage);
            } catch (\Throwable $e) {
                // Eén onbereikbare inbox mag de rest niet blokkeren.
                Log::warning("[mail:notify-new] inbox {$account->id} mislukt: " . $e->getMessage());
                $this->warn("Inbox {$account->email}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    protected function scanAccount(MailboxService $mailbox, MailAccount $account, int $perPage): void
    {
        $result = $mailbox->messages($account, 'INBOX', 1, $perPage);
        $messages = $result['messages'] ?? [];

        if (empty($messages)) {
            return;
        }

        $maxUid = 0;
        foreach ($messages as $m) {
            $maxUid = max($maxUid, (int) ($m['uid'] ?? 0));
        }

        $lastSeen = (int) ($account->last_seen_uid ?? 0);

        // Eerste run (nog nooit gepollt): alleen baseline zetten, niet notificeren.
        if ($account->last_seen_uid === null) {
            $account->last_seen_uid = $maxUid;
            $account->save();
            $this->info("Inbox {$account->email}: baseline op UID {$maxUid} gezet.");
            return;
        }

        $new = array_filter($messages, fn ($m) => (int) ($m['uid'] ?? 0) > $lastSeen);
        // Oudste eerst, zodat de notificaties in volgorde van binnenkomst staan.
        usort($new, fn ($a, $b) => ($a['uid'] ?? 0) <=> ($b['uid'] ?? 0));

        foreach ($new as $m) {
            $from = $m['from'][0] ?? null;
            $sender = $from['name'] ?? ($from['email'] ?? 'Onbekende afzender');
            $subject = trim((string) ($m['subject'] ?? '')) ?: '(geen onderwerp)';

            AppNotification::notifyUser(
                (int) $account->user_id,
                'admin.mail',
                'Nieuwe mail — ' . $account->name,
                $sender . ': ' . $subject,
                [
                    'account_id' => (string) $account->id,
                    'uid'        => (int) $m['uid'],
                    'action_url' => '/admin/mail',
                ],
            );
        }

        if ($maxUid > $lastSeen) {
            $account->last_seen_uid = $maxUid;
            $account->save();
        }

        $count = count($new);
        if ($count > 0) {
            $this->info("Inbox {$account->email}: {$count} nieuwe melding(en).");
        }
    }
}
