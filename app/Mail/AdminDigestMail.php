<?php

namespace App\Mail;

use App\Services\AdminDigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Periodiek admin-overzicht (dag/week/maand/kwartaal/jaar): aanmeldingen met
 * abonnement, herkomst-URL en land, nieuwe leads en de totaalstand. Cijfers
 * komen kant-en-klaar uit AdminDigestService::build().
 */
class AdminDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $kind, public array $data)
    {
        // Het overzicht gaat naar de beheerder en is Nederlandstalig,
        // ongeacht de app-taal (die staat standaard op Engels).
        $this->locale(config('admin_digest.locale', 'nl'));
    }

    public function build()
    {
        $locale = app()->getLocale();
        $from   = $this->data['from']->locale($locale);
        $last   = $this->data['to']->subDay()->locale($locale);

        // Kort label voor de onderwerpregel, lang label voor onder de titel.
        [$short, $long] = match ($this->kind) {
            'day' => [
                $from->translatedFormat('D j M'),
                $from->translatedFormat('l j F Y'),
            ],
            'week' => [
                mb_strtolower(__('mail.admin_digest.week_label', ['week' => $from->isoWeek])),
                __('mail.admin_digest.week_label', ['week' => $from->isoWeek]) . ' · '
                    . $from->translatedFormat('j M') . ' – ' . $last->translatedFormat('j M Y'),
            ],
            'month' => [
                $from->translatedFormat('F Y'),
                $from->translatedFormat('F Y'),
            ],
            'quarter' => [
                __('mail.admin_digest.quarter_label', ['quarter' => $from->quarter, 'year' => $from->year]),
                __('mail.admin_digest.quarter_label', ['quarter' => $from->quarter, 'year' => $from->year]) . ' · '
                    . $from->translatedFormat('F') . ' – ' . $last->translatedFormat('F'),
            ],
            'year' => [
                (string) $from->year,
                (string) $from->year,
            ],
        };

        $subject = __("mail.admin_digest.subject.{$this->kind}", [
            'period'  => $short,
            'signups' => $this->data['signups']['total'],
            'leads'   => $this->data['leads']['total'],
        ]);

        return $this
            ->subject($subject)
            ->view('emails.admin-digest', [
                'kind'      => $this->kind,
                'd'         => $this->data,
                'subject'   => $subject,
                'periodLong' => $long,
                'locale'    => $locale,
                'countryName' => fn (?string $code) => AdminDigestService::countryName($code),
                'shortUrl'  => fn (?string $url) => AdminDigestService::shortUrl($url),
            ]);
    }
}
