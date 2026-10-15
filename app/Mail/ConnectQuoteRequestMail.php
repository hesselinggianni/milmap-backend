<?php
namespace App\Mail;
use Illuminate\Bus\Queueable; use Illuminate\Mail\Mailable; use Illuminate\Queue\SerializesModels;
class ConnectQuoteRequestMail extends Mailable { use Queueable, SerializesModels; public function __construct(public array $quote) {} public function build() { return $this->subject('Nieuwe MilMap Connect offerteaanvraag')->view('emails.connect-quote-request'); } }
