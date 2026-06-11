<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Security-mail wanneer iemand 10x verkeerd de PIN heeft ingevoerd op de
 * MilMap app. De gebruiker wordt aangespoord het wachtwoord te wijzigen en
 * overal uit te loggen — de backend heeft alle tokens al ingetrokken op het
 * moment dat deze mail uitgaat.
 */
class AppLockTripped extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $ipAddress;
    public string $location;
    public string $device;
    public string $time;

    public function __construct(
        ?string $name,
        string $ipAddress,
        string $location,
        string $device,
        string $time,
    ) {
        $this->name = $name ?? 'Gebruiker';
        $this->ipAddress = $ipAddress;
        $this->location = $location;
        $this->device = $device;
        $this->time = $time;
    }

    public function build()
    {
        return $this->subject('Verdachte activiteit — 10 mislukte PIN-pogingen op MilMap')
                    ->view('emails.app-lock-tripped');
    }
}
