<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Mission;
use App\Models\User;
use App\Support\SentMailTracker;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * De uitnodigingsmail moet de app openen (Universal Links / App Links). Dat
 * werkt alleen als de knop rechtstreeks naar app.milmap.nl/invite/… wijst; de
 * klik-tracking-redirect via api.milmap.nl opent altijd de browser.
 */
class InvitationMailAppLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://api.milmap.nl',
            'app.frontend_url' => 'https://app.milmap.nl',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ]);
    }

    private function trackedHtml(string $html): string
    {
        $message = (new Email())->subject('Uitnodiging')->html($html);
        SentMailTracker::prepare($message);

        return $message->getHtmlBody();
    }

    public function test_invitation_links_stay_direct_but_other_links_are_tracked(): void
    {
        $html = $this->trackedHtml(
            '<a href="https://app.milmap.nl/invite/abcDEF123">Bekijk</a>'
            .'<a href="https://app.milmap.nl/invite-web/abcDEF123">Web</a>'
            .'<a href="https://milmap.nl/pricing">Prijzen</a>'
        );

        // Direct naar de app-link, met UTM (HTML-veilig) en zonder klik-wrapper.
        $this->assertStringContainsString(
            'href="https://app.milmap.nl/invite/abcDEF123?utm_source=mail&amp;utm_medium=email&amp;utm_campaign=mail-uitnodiging"',
            $html
        );
        $this->assertStringNotContainsString(rawurlencode('https://app.milmap.nl/invite/abcDEF123'), $html);
        $this->assertSame(2, substr_count($html, 'https://api.milmap.nl/api/v1/m/c/'));
        // De expliciete browserroute en gewone links blijven getrackt.
        $this->assertStringContainsString(rawurlencode('https://app.milmap.nl/invite-web/abcDEF123'), $html);
        $this->assertStringContainsString(rawurlencode('https://milmap.nl/pricing'), $html);
    }

    public function test_app_link_detection_is_limited_to_the_invitation_path_on_our_host(): void
    {
        $this->assertTrue(SentMailTracker::isAppLink('https://app.milmap.nl/invite/abc'));
        $this->assertTrue(SentMailTracker::isAppLink('HTTPS://APP.MILMAP.NL/invite/abc'));
        foreach ([
            'https://app.milmap.nl/invite-web/abc',
            'https://app.milmap.nl/maps',
            'https://app.milmap.nl.evil.example/invite/abc',
            'https://evil.example/https://app.milmap.nl/invite/abc',
            'http://app.milmap.nl/invite/abc',
        ] as $url) {
            $this->assertFalse(SentMailTracker::isAppLink($url), $url);
        }
    }

    public function test_rendered_invitation_mail_button_opens_the_app_directly(): void
    {
        $inviter = new User(['first_name' => 'Kees', 'last_name' => 'Jansen']);
        $mission = new Mission(['name' => 'Oefening Noord']);
        $invite = new Invitation(['email' => 'recipient@example.test', 'token' => 'TokEn123abc', 'role' => 'editor']);
        $invite->invitable_type = Mission::class;
        $invite->setRelation('invitable', $mission);
        $invite->setRelation('inviter', $inviter);

        $html = $this->trackedHtml((new InvitationMail($invite, false))->render());

        $this->assertMatchesRegularExpression('#<a href="https://app\.milmap\.nl/invite/TokEn123abc\?utm_source=mail[^"]*"#', $html);
        $this->assertStringNotContainsString(rawurlencode('https://app.milmap.nl/invite/TokEn123abc'), $html);
    }
}
