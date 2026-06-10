<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to an e-mail address that was invited to chat by a MilMap user.
 * The recipient is not (yet) a MilMap user; the link points to the register
 * page so they can create a free account and start chatting back.
 */
class ChatInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $inviterName,
        public string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviterName} wil met je chatten op Milmap",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chat-invite',
            with: [
                'inviterName' => $this->inviterName,
                'url'         => $this->url,
            ],
        );
    }
}
