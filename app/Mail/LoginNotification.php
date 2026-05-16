<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginNotification extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $ipAddress;
    public string $location;
    public string $device;
    public string $loginTime;

    public function __construct(?string $name, string $ipAddress, string $location, string $device, string $loginTime)
    {
        $this->name = $name ?? 'Gebruiker';
        $this->ipAddress = $ipAddress;
        $this->location = $location;
        $this->device = $device;
        $this->loginTime = $loginTime;
    }

    public function build()
    {
        return $this->subject('Nieuwe inlog gedetecteerd — MilMap')
                    ->view('emails.login-notification');
    }
}
