<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
class ConnectInvitation extends Mailable {
    public function __construct(public string $organizationName, public string $firstName, public string $inviteUrl) {}
    public function build() {
        return $this->subject('Uitnodiging voor MilMap — '.$this->organizationName)
            ->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('X-Milmap-Sensitive', '1'))
            ->view('emails.connect-invitation');
    }
}
