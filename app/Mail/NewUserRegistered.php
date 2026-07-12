<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewUserRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $sourceUrl;
    public $referrer;

    public function __construct($user, $sourceUrl = null, $referrer = null)
    {
        $this->user      = $user;
        $this->sourceUrl = $sourceUrl;
        $this->referrer  = $referrer;
    }

    public function build()
    {
        return $this
            ->subject('Nieuwe gebruiker geregistreerd')
            ->view('emails.new-user-registered');
    }
}