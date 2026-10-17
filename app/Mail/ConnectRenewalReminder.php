<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConnectRenewalReminder extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public string $organizationName, public \DateTimeInterface $endsAt, public int $daysLeft, public int $seats, public string $renewUrl) {}
    public function build()
    {
        return $this->subject('Je MilMap Connect-licentie van '.$this->organizationName.' verloopt binnenkort')->view('emails.connect-renewal-reminder');
    }
}
