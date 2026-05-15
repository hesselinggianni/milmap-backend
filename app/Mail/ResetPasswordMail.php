<?php 

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $resetUrl;

    public function __construct($resetUrl)
    {
        $this->resetUrl = $resetUrl;

    }

    public function build()
    {

        $mailBody = '
        <p>Hello,</p> 
        <p>You are receiving this email because we received a password reset request for your account. </p>
        <div class="btn btn-primary">
        <a  href="'.$this->resetUrl.'">Reset Password</a>
        </div>
        <br>
        <p>This password reset link will expire in 60 minutes.</p>

        <p>If you did not request a password reset, no further action is required.</p>
        <br>
        <p>Regards,</p>
        <p>Onavan</p>
        <br>
        <hr>
        <br>
        <p>If you’re having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser: '.$this->resetUrl.'</p>';



        return $this->subject('Reset password notification')
                    ->html(onavan_mail_template('Reset password notification', $mailBody));
    }

}
