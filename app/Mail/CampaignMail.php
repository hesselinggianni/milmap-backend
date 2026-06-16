<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Generieke campagne-mail. Krijgt een AL GERESOLVEDE view + onderwerp (zie
 * App\Services\MailTemplateRegistry::resolve) zodat de mailable deterministisch en
 * queue-veilig is. Voegt de tracking-pixel + afmeldlink als view-data toe en zet de
 * List-Unsubscribe-headers (one-click) voor mailclients.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $viewName,
        public string $subjectLine,
        public array $data = [],
        public ?string $unsubscribeUrl = null,
        public ?string $pixelUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: $this->viewName,
            with: array_merge($this->data, [
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'pixelUrl'       => $this->pixelUrl,
            ]),
        );
    }

    public function headers(): Headers
    {
        $text = [];
        if ($this->unsubscribeUrl) {
            // Eén-klik afmelden volgens RFC 8058 (Gmail/Apple tonen een "Unsubscribe"-knop).
            $text['List-Unsubscribe'] = '<' . $this->unsubscribeUrl . '>';
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    public function attachments(): array
    {
        return [];
    }
}
