<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegisterInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public $workspace;
    public $invitation;
    public $inviter;

    public function __construct($workspace, $invitation)
    {
        $this->workspace = $workspace;
        $this->invitation = $invitation;
        $this->inviter = $invitation->inviter; // Haal de inviter op via de relatie
    }

    public function build()
    {
        return $this->subject('You\'ve been invited to a workspace in Onavan!')
            ->view('emails.register-invite')
            ->with([
                'workspace' => $this->workspace,
                'invitation' => $this->invitation,
                'inviter' => $this->inviter, // Voeg de inviter toe
            ]);
    }
}
