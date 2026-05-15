<?php 
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

class MailTemplate extends Mailable
{
    public $subject;
    public $messageContent;
    public $attachments;

    /**
     * Maak een nieuwe e-mail instantie aan.
     */
    public function __construct(
        $subject, $messageContent, $attachments = []
    )
    {
        $this->subject = $subject;
        $this->messageContent = $messageContent;
        $this->attachments = $attachments;
    }

    /**
     * Verkrijg de e-mail envelop configuratie.
     */
    public function envelope(): Envelope
    {
        $fromEmail = $this->department['email'] ?? 'gianni@onavan.com';
        $fromName = $this->department['name'] ?? 'MilMAP - by Onavan';

      
        return new Envelope(
            // from: new Address($fromEmail, $fromName),
            // to: array_filter(array_map(fn($email) => is_string($email) ? new Address($email, 'Ontvanger Naam') : null, $this->to)),
            subject: $this->subject,
            // replyTo: [
            //     new Address('noreply@example.com', 'No Reply'),
            // ],
            // tags: ['ticket', 'support'],
            // metadata: [
            //     'ticket-id' => $this->ticket_id,
            // ]
        );
    }

    /**
     * Bouw de e-mail.
     */
    public function build()
    {
        // Genereren van de e-mailinhoud met de template
        $emailContent = onavan_mail_template_simple($this->subject, $this->messageContent);

        // E-mail opbouwen
        $email = $this->subject($this->subject)
                      ->html($emailContent); 

        // Voeg bijlagen toe als ze beschikbaar zijn
        foreach ($this->attachments as $attachment) {
            // Controleer of het een string is (bestandspad) of een array met 'file' en 'options'
            if (is_string($attachment)) {
                $email->attach($attachment); // Als het bestandspad is
            } else {
                // Als het een array is met 'file' en 'options'
                $email->attach($attachment['file'], $attachment['options'] ?? []);
            }
        }

        // Voeg een custom header toe met het ticket ID
        $email->withSwiftMessage(function ($message) {
            $message->getHeaders()->addTextHeader('X-Ticket-ID', $this->ticket_id);
        });

        return $email;
    }
}
