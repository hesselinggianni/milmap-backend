<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\AppNotification;
use App\Models\MailSend;
use App\Services\MailTemplateRegistry;
use App\Services\NotificationPreferenceService;
use App\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Verstuurt één mail_sends-rij (campagne óf follow-up). Idempotent: een al verzonden rij
 * wordt overgeslagen. De opt-out-check gebeurt HIER (op verzendmoment), zodat een
 * tussentijdse afmelding meetelt; transactionele categorieën worden nooit geblokkeerd.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    public function __construct(public int $sendId) {}

    public function handle(): void
    {
        $send = MailSend::with('category')->find($this->sendId);
        if (! $send || $send->status === 'sent') {
            return; // verwijderd of al verzonden → niets te doen
        }

        $resolved = MailTemplateRegistry::resolve($send->template_key, $send->language);
        if (! $resolved) {
            $send->update(['status' => 'failed', 'failure_reason' => 'Onbekende template: ' . $send->template_key]);
            return;
        }

        // Kanaalvoorkeuren op verzendmoment (zodat een tussentijdse wijziging meetelt).
        // Transactionele categorieën gaan altijd per e-mail uit (nooit geblokkeerd).
        $isTransactional = (bool) ($send->category?->is_transactional);
        $wantsEmail = $isTransactional || NotificationPreferenceService::wantsEmail($send->email, $send->category_id);
        $wantsPush  = NotificationPreferenceService::wantsPush($send->user_id, $send->category_id);

        if (! $wantsEmail && ! $wantsPush) {
            $send->update(['status' => 'skipped_optout']);
            return;
        }

        // De tracking-/afmeld-routes draaien op de backend (APP_URL), niet op de SPA.
        $base    = rtrim(config('app.url') ?: 'http://localhost:8000', '/');
        $appUrl  = config('app.app_url', 'https://app.milmap.nl');
        $unsub   = $base . '/api/v1/m/u/' . $send->token;
        $pixel   = $base . '/api/v1/m/o/' . $send->token . '.gif';
        $data    = ['name' => $send->name, 'appUrl' => $appUrl, 'siteUrl' => 'https://milmap.nl'];
        $subject = $send->subject ?: $resolved['subject'];
        $mail    = new CampaignMail($resolved['view'], $subject, $data, $unsub, $pixel);

        $delivered = false;
        $emailError = null;

        // ── Push / in-app — vóór e-mail, idempotent (zodat een retry op een
        //    mail-fout nooit een dubbele in-app melding maakt). ──
        if ($wantsPush && $send->user_id) {
            $exists = AppNotification::where('user_id', $send->user_id)
                ->where('payload->send_token', $send->token)->exists();
            if (! $exists) {
                $snippet = null;
                try {
                    $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($mail->render()))), 0, 160);
                } catch (\Throwable $e) { /* render-snippet best-effort */ }

                AppNotification::notifyUser(
                    (int) $send->user_id,
                    'campaign.' . ($send->category?->key ?? 'mail'),
                    $subject,
                    $snippet,
                    ['send_token' => $send->token, 'action' => 'campaign_mail', 'campaign_id' => (string) $send->campaign_id],
                );
                PushSender::send((int) $send->user_id, $subject, $snippet, ['send_token' => $send->token]);
            }
            $delivered = true;
        }

        // ── E-mail ──
        if ($wantsEmail) {
            try {
                Mail::to($send->email)->send($mail);
                $delivered = true;
            } catch (\Throwable $e) {
                if (! $delivered) {
                    // Geen enkel kanaal geslaagd → laat de queue retryen.
                    $send->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
                    throw $e;
                }
                // Push lukte al → markeer als verzonden, maar bewaar de mailfout
                // (niet op null zetten) zodat het verlies van het e-mailkanaal zichtbaar blijft.
                $emailError = mb_substr('E-mail mislukt (in-app wel geplaatst): ' . $e->getMessage(), 0, 255);
                Log::warning('Campagne-mail mislukt maar in-app melding geplaatst', [
                    'sendId' => $send->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $send->update(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => $emailError]);
    }

    public function failed(\Throwable $e): void
    {
        $send = MailSend::find($this->sendId);
        if ($send && $send->status !== 'sent') {
            $send->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
        }
        Log::error('SendCampaignMessage failed', ['sendId' => $this->sendId, 'error' => $e->getMessage()]);
    }
}
