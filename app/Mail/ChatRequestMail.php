<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to an existing MilMap user when someone wants to start chatting with
 * them. Unlike ChatInviteMail (which targets people without an account), this
 * recipient already has an account and must accept the request in-app before
 * a conversation is opened.
 */
class ChatRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $requesterName,
        public string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->requesterName} wil met je chatten op MilMap",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chat-request',
            with: [
                'requesterName' => $this->requesterName,
                'url'           => $this->url,
            ],
        );
    }
}
