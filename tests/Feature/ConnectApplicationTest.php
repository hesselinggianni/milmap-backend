<?php

namespace Tests\Feature;

use App\Mail\ConnectQuoteRequestMail;
use App\Models\CrmDeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConnectApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_application_is_saved_in_crm_and_emailed_without_creating_an_account(): void
    {
        Mail::fake();
        $data = ['company_name' => 'Voorbeeld organisatie', 'contact_name' => 'Sam Jansen',
            'email' => 'sam@example.test', 'billing_email' => 'facturen@example.test',
            'phone' => '+31000000000', 'seat_count' => 100, 'purpose' => 'Training',
            'requirements' => 'MDM en eigen huisstijl'];
        $response = $this->postJson('/api/v1/connect/quote-requests', $data)->assertCreated();
        $this->assertDatabaseHas('connect_quote_requests', ['id' => $response->json('id'), 'billing_email' => $data['billing_email']]);
        $deal = CrmDeal::where('contact_email', $data['email'])->sole();
        $this->assertSame('lead', $deal->stage);
        $this->assertContains('Connect', $deal->tags);
        $this->assertStringContainsString('100', $deal->notes);
        $this->assertStringContainsString($data['requirements'], $deal->notes);
        $this->assertSame($response->json('id'), $deal->activities()->sole()->meta['connect_quote_request_id']);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        Mail::assertSent(ConnectQuoteRequestMail::class, fn ($mail) => $mail->hasTo('hello@milmap.nl') && $mail->quote['billing_email'] === $data['billing_email']);
    }

    public function test_email_renders_when_optional_fields_are_omitted(): void
    {
        $mail = new ConnectQuoteRequestMail(['company_name' => 'Voorbeeld',
            'contact_name' => 'Sam Jansen', 'email' => 'sam@example.test', 'seat_count' => 50]);
        $html = $mail->render();
        $this->assertStringContainsString('Voorbeeld', $html);
        $this->assertStringContainsString('https://admin.milmap.nl/sales', $html);
    }

    public function test_invalid_application_does_not_create_a_lead_or_send_email(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/connect/quote-requests', ['email' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('connect_quote_requests', 0);
        $this->assertDatabaseCount('crm_deals', 0);
        Mail::assertNothingSent();
    }
}
