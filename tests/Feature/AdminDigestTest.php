<?php

namespace Tests\Feature;

use App\Mail\AdminDigestMail;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Periodieke admin-overzichtsmails (digest:send): periodegrenzen in Amsterdamse
 * tijd, wie zich heeft aangemeld met abonnement/URL/land, leads, totaalstand en
 * het verzendschema.
 */
class AdminDigestTest extends TestCase
{
    use RefreshDatabase;

    private const PRO_MONTHLY = 'price_test_pro_monthly';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.stripe_price_pro_monthly' => self::PRO_MONTHLY]);
    }

    /** Peilmoment: de ochtend van 29 okt 2026 (Amsterdam) → het dagoverzicht dekt 28 okt. */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-29 07:00', 'Europe/Amsterdam');
    }

    private function user(string $email, string $createdAtAmsterdam, array $extra = []): User
    {
        $user = User::unguarded(fn () => User::create($extra + [
            'first_name' => 'Test',
            'last_name'  => 'Persoon',
            'email'      => $email,
            'password'   => bcrypt('x'),
        ]));
        $user->forceFill(['created_at' => CarbonImmutable::parse($createdAtAmsterdam, 'Europe/Amsterdam')->utc()])->saveQuietly();

        return $user;
    }

    private function lead(string $email, string $createdAtAmsterdam, array $extra = []): Lead
    {
        $lead = Lead::create($extra + ['email' => $email, 'source' => 'app-landing']);
        $lead->forceFill(['created_at' => CarbonImmutable::parse($createdAtAmsterdam, 'Europe/Amsterdam')->utc()])->saveQuietly();

        return $lead;
    }

    private function subscription(User $user, string $price, string $createdAtAmsterdam, array $extra = []): Subscription
    {
        $sub = Subscription::create($extra + [
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1,
        ]);
        $sub->forceFill(['created_at' => CarbonImmutable::parse($createdAtAmsterdam, 'Europe/Amsterdam')->utc()])->saveQuietly();

        return $sub;
    }

    public function test_periods_are_the_previous_full_calendar_period_in_amsterdam_time(): void
    {
        $s = new AdminDigestService;
        $now = CarbonImmutable::parse('2026-10-29 07:00', 'Europe/Amsterdam'); // donderdag

        $fmt = fn (string $kind) => collect($s->period($kind, $now))->map->format('Y-m-d H:i')->values()->all();

        $this->assertSame(['2026-10-28 00:00', '2026-10-29 00:00'], $fmt('day'));
        $this->assertSame(['2026-10-19 00:00', '2026-10-26 00:00'], $fmt('week'));      // ma–zo
        $this->assertSame(['2026-09-01 00:00', '2026-10-01 00:00'], $fmt('month'));
        $this->assertSame(['2026-07-01 00:00', '2026-10-01 00:00'], $fmt('quarter'));
        $this->assertSame(['2025-01-01 00:00', '2026-01-01 00:00'], $fmt('year'));

        // Eerste van de maand/het jaar: de periode die net is afgelopen.
        $first = CarbonImmutable::parse('2027-01-01 07:20', 'Europe/Amsterdam');
        $this->assertSame(['2026-12-01 00:00', '2027-01-01 00:00'], collect($s->period('month', $first))->map->format('Y-m-d H:i')->values()->all());
        $this->assertSame(['2026-10-01 00:00', '2027-01-01 00:00'], collect($s->period('quarter', $first))->map->format('Y-m-d H:i')->values()->all());
        $this->assertSame(['2026-01-01 00:00', '2027-01-01 00:00'], collect($s->period('year', $first))->map->format('Y-m-d H:i')->values()->all());
    }

    public function test_day_digest_lists_signups_with_plan_url_and_country_and_ignores_other_days_and_demo(): void
    {
        // Gisteren (28 okt, Amsterdam): 00:30 en 23:30 horen erbij, ook al is 23:30 CET/CEST in UTC nog dezelfde dag.
        $free = $this->user('free@example.test', '2026-10-28 00:30', [
            'trial_ends_at' => $this->now()->addDays(5),
            'signup_source_url' => 'https://www.milmap.nl/app/?utm=x', 'signup_referrer' => 'https://www.google.com/', 'signup_country' => 'NL',
        ]);
        $paid = $this->user('paid@example.test', '2026-10-28 23:30', ['signup_source_url' => 'ios-app /register', 'signup_country' => 'DE']);
        $this->subscription($paid, self::PRO_MONTHLY, '2026-10-28 23:45');

        // Erbuiten: eergisteren, vandaag en een demo-account.
        $this->user('older@example.test', '2026-10-27 23:59');
        $this->user('today@example.test', '2026-10-29 00:00');
        $this->user('demo@example.test', '2026-10-28 12:00', ['is_demo' => true]);

        $data = (new AdminDigestService)->build('day', $this->now());

        $this->assertSame(2, $data['signups']['total']);
        $rows = collect($data['signups']['rows'])->keyBy('email');
        $this->assertSame(['paid@example.test', 'free@example.test'], collect($data['signups']['rows'])->pluck('email')->all(), 'nieuwste eerst');

        $this->assertSame('trial', $rows['free@example.test']['plan']);
        $this->assertSame('NL', $rows['free@example.test']['country']);
        $this->assertSame('https://www.google.com/', $rows['free@example.test']['referrer']);
        $this->assertSame('email', $rows['free@example.test']['method']);

        $this->assertSame('pro_monthly', $rows['paid@example.test']['plan']);
        $this->assertSame('ios-app /register', $rows['paid@example.test']['url']);
        $this->assertSame('DE', $rows['paid@example.test']['country']);

        $this->assertEquals(['trial' => 1, 'pro_monthly' => 1], $data['breakdown']['signups_by_plan']);
        $this->assertEqualsCanonicalizing(['milmap.nl/app', 'ios-app'], array_column($data['breakdown']['signups_by_source']['rows'], 'label'));
        $this->assertEqualsCanonicalizing(['NL', 'DE'], array_column($data['breakdown']['signups_by_country']['rows'], 'label'));

        // Het abonnement dat gisteren is afgesloten.
        $this->assertSame(1, $data['subscriptions']['total']);
        $this->assertSame('pro_monthly', $data['subscriptions']['rows'][0]['plan']);
    }

    public function test_leads_and_comparison_with_previous_period(): void
    {
        $this->lead('lead1@example.test', '2026-10-28 10:00', ['source_url' => 'https://milmap.nl/app', 'country' => 'NL', 'funnel_step' => 3]);
        $this->lead('lead2@example.test', '2026-10-28 11:00', ['source' => 'pricing-exit']);
        $this->lead('before@example.test', '2026-10-27 11:00');
        $this->user('prev@example.test', '2026-10-27 09:00');

        // Een lead die een gebruiker is geworden telt niet óók als lead: zowel
        // met converted_at als (oudere accounts) alleen op basis van het e-mailadres.
        $this->lead('converted@example.test', '2026-10-28 12:00', ['converted_at' => now()]);
        $this->lead('registered@example.test', '2026-10-28 13:00');
        $this->user('registered@example.test', '2026-10-28 13:30');

        $data = (new AdminDigestService)->build('day', $this->now());

        $this->assertSame(2, $data['leads']['total']);
        $this->assertEqualsCanonicalizing(['lead1@example.test', 'lead2@example.test'], array_column($data['leads']['rows'], 'email'));
        // Het account zelf staat wél bij de aanmeldingen.
        $this->assertSame(1, $data['signups']['total']);
        $this->assertSame(['signups' => 1, 'leads' => 1, 'subscriptions' => 0, 'cancellations' => 0], $data['previous']);
        $this->assertSame('NL', collect($data['leads']['rows'])->firstWhere('email', 'lead1@example.test')['country']);
        // Zonder URL valt de bron terug op het formulier-label.
        $this->assertContains('pricing-exit', array_column($data['breakdown']['leads_by_source']['rows'], 'label'));
    }

    public function test_totals_count_everyone_not_just_the_period(): void
    {
        $this->user('a@example.test', '2026-01-05 10:00', ['trial_ends_at' => $this->now()->addDay()]);
        $this->user('b@example.test', '2026-02-05 10:00');
        $payer = $this->user('c@example.test', '2026-03-05 10:00', ['trial_ends_at' => $this->now()->addDay()]);
        $this->subscription($payer, self::PRO_MONTHLY, '2026-03-06 10:00');
        $this->user('archived@example.test', '2026-03-05 10:00', ['archived_at' => now()]);
        $this->user('demo@example.test', '2026-03-05 10:00', ['is_demo' => true]);
        $this->lead('l1@example.test', '2026-01-01 10:00');
        $this->lead('l2@example.test', '2026-01-02 10:00', ['converted_at' => now()]);
        $this->lead('a@example.test', '2026-01-03 10:00'); // bestaand account → geen lead meer

        $t = (new AdminDigestService)->build('day', $this->now())['totals'];

        $this->assertSame(3, $t['users']);          // demo + gearchiveerd tellen niet
        $this->assertSame(1, $t['users_paying']);
        $this->assertSame(1, $t['users_on_trial']);  // betalende op proef telt niet dubbel
        $this->assertSame(1, $t['users_free']);
        $this->assertSame(['pro_monthly' => 1], $t['paid_by_plan']);
        $this->assertSame(1, $t['leads']);   // alleen l1: l2 is geconverteerd, a@ is al gebruiker
    }

    public function test_week_digest_has_a_daily_trend(): void
    {
        $this->user('w1@example.test', '2026-10-20 12:00');
        $this->user('w2@example.test', '2026-10-20 13:00');
        $this->user('w3@example.test', '2026-10-25 23:30');

        $data = (new AdminDigestService)->build('week', $this->now());

        $this->assertSame(3, $data['signups']['total']);
        $this->assertCount(7, $data['trend']);
        $this->assertSame([0, 2, 0, 0, 0, 0, 1], array_column($data['trend'], 'signups'));
    }

    public function test_registration_and_leads_store_their_origin_for_the_digest(): void
    {
        Mail::fake();
        // Zonder dit haalt BlockCountries een door de client meegestuurde CF-IPCountry weg (vervalsbaar);
        // in productie komt het land dan uit het IP + de lokale GeoIP-database.
        config(['geoblock.trust_cloudflare' => true]);

        // Land komt uit de Cloudflare-header (of de lokale GeoIP-database), nooit uit het request-body.
        $this->withHeaders(['CF-IPCountry' => 'BE'])->postJson('/api/v1/register', [
            'email' => 'origin@example.test',
            'source_url' => 'https://milmap.nl/app?utm_source=pinterest#hero',
            'referrer' => 'https://www.pinterest.com/',
            'country' => 'US', // wordt genegeerd
        ])->assertCreated();

        $user = User::where('email', 'origin@example.test')->firstOrFail();
        $this->assertSame('https://milmap.nl/app?utm_source=pinterest#hero', $user->signup_source_url);
        $this->assertSame('https://www.pinterest.com/', $user->signup_referrer);
        $this->assertSame('BE', $user->signup_country);

        // Native app-aanmelding krijgt het platform-label i.p.v. https://localhost.
        $this->withHeaders(['CF-IPCountry' => 'DE'])->postJson('/api/v1/register', [
            'email' => 'native@example.test', 'source_url' => 'capacitor://localhost/register',
        ])->assertCreated();
        $this->assertSame('ios-app /register', User::where('email', 'native@example.test')->value('signup_source_url'));

        $this->withHeaders(['CF-IPCountry' => 'FR'])->postJson('/api/v1/leads', [
            'email' => 'lead-origin@example.test', 'source' => 'app2-landing',
            'source_url' => 'https://milmap.nl/app2', 'referrer' => 'https://duckduckgo.com/',
        ])->assertSuccessful();

        $lead = Lead::where('email', 'lead-origin@example.test')->firstOrFail();
        $this->assertSame('https://milmap.nl/app2', $lead->source_url);
        $this->assertSame('https://duckduckgo.com/', $lead->referrer);
        $this->assertSame('FR', $lead->country);

        // De losse admin-mails per registratie/lead bestaan niet meer: dat loopt via het overzicht.
        Mail::assertNotSent(fn (\Illuminate\Mail\Mailable $mail) => $mail->hasTo('hesselinggianni@gmail.com'));
    }

    public function test_command_mails_the_digest_in_dutch_with_the_expected_content(): void
    {
        Mail::fake();
        $this->user('nieuw@example.test', '2026-10-28 14:32', [
            'trial_ends_at' => $this->now()->addDays(5),
            'signup_source_url' => 'https://milmap.nl/app', 'signup_country' => 'NL',
        ]);

        $this->artisan('digest:send', ['kind' => 'day', '--date' => '2026-10-29', '--to' => 'boss@example.test'])->assertSuccessful();

        Mail::assertSent(AdminDigestMail::class, function (AdminDigestMail $mail) {
            $html = $mail->render();
            $this->assertTrue($mail->hasTo('boss@example.test'));
            $this->assertStringContainsString('Dagoverzicht', $html);
            $this->assertStringContainsString('woensdag 28 oktober 2026', $html);
            $this->assertStringContainsString('nieuw@example.test', $html);
            $this->assertStringContainsString('Gratis · proef', $html);
            $this->assertStringContainsString('Nederland (NL)', $html);
            $this->assertStringContainsString('milmap.nl/app', $html);
            $this->assertStringContainsString('Totaal nu', $html);

            // render() bouwt de mail binnen de mail-locale, daarna staat het onderwerp vast.
            $this->assertSame('MilMap dagoverzicht wo. 28 okt. — 1 aanmeldingen, 0 leads', $mail->subject);

            return true;
        });
    }

    public function test_empty_period_still_sends_a_mail_with_totals(): void
    {
        Mail::fake();

        $this->artisan('digest:send', ['kind' => 'year', '--date' => '2027-01-01', '--to' => 'boss@example.test'])->assertSuccessful();

        Mail::assertSent(AdminDigestMail::class, function (AdminDigestMail $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Jaaroverzicht', $html);
            $this->assertStringContainsString('Geen nieuwe aanmeldingen in deze periode.', $html);
            $this->assertStringContainsString('Totaal nu', $html);

            return true;
        });
    }

    public function test_unknown_kind_is_rejected(): void
    {
        Mail::fake();
        $this->artisan('digest:send', ['kind' => 'decade'])->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_schedule_sends_each_digest_at_the_right_time_in_amsterdam(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'digest:send'));
        $this->assertCount(5, $events);

        $dueAt = function (string $kind, string $amsterdamTime) use ($events): bool {
            $event = $events->first(fn ($e) => str_contains($e->command, "digest:send {$kind}") || str_contains($e->command, "digest:send '{$kind}'"));
            $this->assertNotNull($event, "geen schema voor {$kind}");
            $this->assertSame('Europe/Amsterdam', (string) $event->timezone);
            $this->travelTo(CarbonImmutable::parse($amsterdamTime, 'Europe/Amsterdam'));

            return $event->isDue(app());
        };

        $this->assertTrue($dueAt('day', '2026-10-29 07:00'));
        $this->assertFalse($dueAt('day', '2026-10-29 08:00'));
        $this->assertTrue($dueAt('week', '2026-11-02 07:05'));      // maandag
        $this->assertFalse($dueAt('week', '2026-11-03 07:05'));
        $this->assertTrue($dueAt('month', '2026-11-01 07:10'));
        $this->assertFalse($dueAt('month', '2026-11-02 07:10'));
        $this->assertTrue($dueAt('quarter', '2027-01-01 07:15'));
        $this->assertTrue($dueAt('quarter', '2026-10-01 07:15'));
        $this->assertFalse($dueAt('quarter', '2026-11-01 07:15'));
        $this->assertTrue($dueAt('year', '2027-01-01 07:20'));
        $this->assertFalse($dueAt('year', '2026-10-01 07:20'));
    }
}
