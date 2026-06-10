<?php

namespace App\Mail;

use App\Models\Map;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CollaborationInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Map $map,
        public User $inviter,
        public string $invitationToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviter->first_name} invited you to collaborate on a Milmap",
        );
    }

    public function content(): Content
    {
        $acceptLink = config('app.frontend_url') . '/invitations/' . $this->invitationToken . '/accept';

        return new Content(
            view: 'emails.collaboration-invite',
            with: [
                'user' => $this->user,
                'inviter' => $this->inviter,
                'map' => $this->map,
                'acceptLink' => $acceptLink,
            ],
        );
    }
}
