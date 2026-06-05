<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent on behalf of the inviting user when someone is invited (by e-mail) to
 * collaborate on a mission or map. Works for both existing MilMap users and
 * brand-new recipients who will register a free view-only account.
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public bool $isNewUser,
    ) {
    }

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter;
        $who = $inviter?->first_name ?: ($inviter?->full_name ?? 'Iemand');

        return new Envelope(
            subject: "{$who} nodigt je uit voor \"{$this->invitation->resourceTitle()}\" op MilMap",
        );
    }

    public function content(): Content
    {
        $acceptUrl = rtrim(config('app.frontend_url'), '/') . '/invite/' . $this->invitation->token;

        return new Content(
            view: 'emails.invitation',
            with: [
                'invitation'    => $this->invitation,
                'inviter'       => $this->invitation->inviter,
                'inviterName'   => $this->invitation->inviter?->full_name ?? 'Een MilMap-gebruiker',
                'resourceTitle' => $this->invitation->resourceTitle(),
                'resourceLabel' => $this->invitation->resourceLabel(),
                'acceptUrl'     => $acceptUrl,
                'isNewUser'     => $this->isNewUser,
            ],
        );
    }
}
