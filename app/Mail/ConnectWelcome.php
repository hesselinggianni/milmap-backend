<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class ConnectWelcome extends Mailable {
    use Queueable, SerializesModels;
    public function __construct(public string $organizationName, public string $firstName, public string $email, public string $password) {}
    public function build() { return $this->subject('Welkom bij MilMap via '.$this->organizationName)->view('emails.connect-welcome'); }
}
