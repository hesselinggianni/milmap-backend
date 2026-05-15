<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\NotificationMail; // Custom mailable class
use Illuminate\Support\Facades\Log;

class SendEmailNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3; // Number of attempts for failed jobs
    public $backoff = 60; // Retry delay in seconds

    protected $emailData;

    public function __construct($emailData)
    {
        $this->emailData = $emailData;
    }

    public function handle()
    {
        // Send the email using the NotificationMail Mailable class
        Mail::to($this->emailData['recipient'])
            ->send(new NotificationMail($this->emailData));
    }

    public function failed(\Exception $exception)
    {
        // Logic for handling failure, such as logging
        Log::error("Failed to send email to {$this->emailData['recipient']}", [
            'error' => $exception->getMessage(),
            'emailData' => $this->emailData,
        ]);
    }
}
