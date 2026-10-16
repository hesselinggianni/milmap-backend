<?php
namespace App\Mail;
use Illuminate\Bus\Queueable; use Illuminate\Mail\Mailable; use Illuminate\Queue\SerializesModels;
class ConnectQuoteRequestMail extends Mailable { use Queueable, SerializesModels; public function __construct(public array $quote) {} public function build() { return $this->subject('Nieuwe MilMap Connect offerteaanvraag')->replyTo($this->quote['email'], $this->quote['contact_name'])->view('emails.connect-quote-request'); } }
