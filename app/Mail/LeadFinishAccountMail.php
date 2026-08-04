<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Retry-mail voor leads die de start-funnel begonnen (e-mail ingevuld) maar
 * geen account hebben afgemaakt: nodigt uit om af te maken, met een unieke
 * 20%-kortingscode die kort geldig is. Verstuurd direct (buiten de
 * campagne-queue) door LeadNurtureService — zelfde patroon als AppDownloadMail.
 */
class LeadFinishAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Kleine, losstaande vertaaltabel — deze mail loopt buiten de
     * campagne-queue/MailTemplateRegistry om (zie LeadNurtureService), dus
     * geen losse blade per taal nodig; gewoon een array per taalcode.
     * Onbekende/ontbrekende taal → 'en' (nooit stilzwijgend Nederlands naar
     * een niet-Nederlandse lead).
     */
    private const STRINGS = [
        'nl' => [
            'subject'    => 'Maak je MilMap-account af — 20% korting, 2 dagen geldig',
            'preheader'  => 'Je bent er bijna — maak je MilMap-account af en krijg 20% korting op je eerste factuur. Nog 2 dagen geldig.',
            'title'      => 'Maak je MilMap-account af',
            'eyebrow'    => 'Je bent er bijna',
            'intro'      => 'Je was bezig met aanmelden bij MilMap — plan routes, deel kaarten en werk samen met je team, zelfs offline in het veld.',
            'codeLabel'  => 'Jouw persoonlijke code',
            'discount'   => '20% korting op je eerste factuur',
            'button'     => 'Account afmaken',
            'oneTime'    => 'Eénmalig te gebruiken — je code werkt automatisch mee op de knop hierboven.',
            'validUntil' => 'Geldig t/m :date.',
            'questions'  => 'Vragen? Mail ons gerust op',
            'disclaimer' => 'Je ontvangt deze e-mail omdat je je e-mailadres hebt ingevuld op app.milmap.nl.',
            'dateLocale' => 'nl',
        ],
        'en' => [
            'subject'    => 'Finish setting up your MilMap account — 20% off, valid for 2 days',
            'preheader'  => 'Almost there — finish your MilMap account and get 20% off your first invoice. Valid for 2 more days.',
            'title'      => 'Finish setting up your MilMap account',
            'eyebrow'    => 'You’re almost there',
            'intro'      => 'You started signing up for MilMap — plan routes, share maps and work with your team, even offline in the field.',
            'codeLabel'  => 'Your personal code',
            'discount'   => '20% off your first invoice',
            'button'     => 'Finish my account',
            'oneTime'    => 'Single use — your code is applied automatically via the button above.',
            'validUntil' => 'Valid until :date.',
            'questions'  => 'Questions? Feel free to email us at',
            'disclaimer' => 'You’re receiving this email because you entered your email address on app.milmap.nl.',
            'dateLocale' => 'en',
        ],
        'de' => [
            'subject'    => 'Schließe dein MilMap-Konto ab — 20% Rabatt, noch 2 Tage gültig',
            'preheader'  => 'Fast geschafft — schließe dein MilMap-Konto ab und erhalte 20% Rabatt auf deine erste Rechnung. Noch 2 Tage gültig.',
            'title'      => 'Schließe dein MilMap-Konto ab',
            'eyebrow'    => 'Du bist fast fertig',
            'intro'      => 'Du hast dich bei MilMap angemeldet — plane Routen, teile Karten und arbeite mit deinem Team zusammen, auch offline im Feld.',
            'codeLabel'  => 'Dein persönlicher Code',
            'discount'   => '20% Rabatt auf deine erste Rechnung',
            'button'     => 'Konto abschließen',
            'oneTime'    => 'Einmal verwendbar — dein Code wird über den Button oben automatisch angewendet.',
            'validUntil' => 'Gültig bis :date.',
            'questions'  => 'Fragen? Schreib uns gerne an',
            'disclaimer' => 'Du erhältst diese E-Mail, weil du deine E-Mail-Adresse auf app.milmap.nl eingegeben hast.',
            'dateLocale' => 'de',
        ],
        'es' => [
            'subject'    => 'Completa tu cuenta de MilMap — 20% de descuento, válido 2 días',
            'preheader'  => 'Ya casi está — completa tu cuenta de MilMap y obtén un 20% de descuento en tu primera factura. Válido 2 días más.',
            'title'      => 'Completa tu cuenta de MilMap',
            'eyebrow'    => 'Ya casi lo tienes',
            'intro'      => 'Empezaste a registrarte en MilMap — planifica rutas, comparte mapas y trabaja con tu equipo, incluso sin conexión sobre el terreno.',
            'codeLabel'  => 'Tu código personal',
            'discount'   => '20% de descuento en tu primera factura',
            'button'     => 'Completar mi cuenta',
            'oneTime'    => 'Un solo uso — tu código se aplica automáticamente con el botón de arriba.',
            'validUntil' => 'Válido hasta :date.',
            'questions'  => '¿Dudas? Escríbenos a',
            'disclaimer' => 'Recibes este correo porque introdujiste tu dirección de email en app.milmap.nl.',
            'dateLocale' => 'es',
        ],
        'fr' => [
            'subject'    => 'Termine la création de ton compte MilMap — 20% de réduction, valable 2 jours',
            'preheader'  => 'Tu y es presque — termine ton compte MilMap et profite de 20% de réduction sur ta première facture. Valable encore 2 jours.',
            'title'      => 'Termine la création de ton compte MilMap',
            'eyebrow'    => 'Tu y es presque',
            'intro'      => 'Tu as commencé à t’inscrire sur MilMap — planifie des itinéraires, partage des cartes et collabore avec ton équipe, même hors ligne sur le terrain.',
            'codeLabel'  => 'Ton code personnel',
            'discount'   => '20% de réduction sur ta première facture',
            'button'     => 'Terminer mon compte',
            'oneTime'    => 'Usage unique — ton code s’applique automatiquement via le bouton ci-dessus.',
            'validUntil' => 'Valable jusqu’au :date.',
            'questions'  => 'Des questions ? Écris-nous à',
            'disclaimer' => 'Tu reçois cet e-mail car tu as saisi ton adresse e-mail sur app.milmap.nl.',
            'dateLocale' => 'fr',
        ],
    ];

    public function __construct(
        public string $couponCode,
        public string $couponExpiresLabel,
        public string $continueUrl,
        public ?string $leadLocale = null,
    ) {}

    public function build()
    {
        $lang = self::STRINGS[$this->leadLocale] ?? self::STRINGS['en'];

        return $this->subject($lang['subject'])
                    ->view('emails.lead-finish-account')
                    ->with(['lang' => $lang, 'localeCode' => $this->leadLocale ?: 'en']);
    }
}
