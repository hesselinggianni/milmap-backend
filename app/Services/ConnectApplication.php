<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\ConnectQuoteRequest;
use App\Models\CrmDeal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConnectApplication
{
    public function create(array $data): ConnectQuoteRequest
    {
        [$quote, $deal] = DB::transaction(function () use ($data) {
            $quote = ConnectQuoteRequest::create($data);
            $notes = implode("\n", [
                'Herkomst: MilMap Connect /start — enterprise samenwerking',
                'Aanvraagnummer: '.$quote->id,
                'Aantal licenties: '.$quote->seat_count,
                'Facturatie e-mailadres: '.($quote->billing_email ?: '—'),
                'Doeleinde: '.($quote->purpose ?: '—'),
                'Specifieke wensen: '.($quote->requirements ?: '—'),
            ]);
            $deal = CrmDeal::create([
                'title' => Str::limit('Connect — '.$quote->company_name, 190, ''),
                'contact_name' => Str::limit($quote->contact_name, 160, ''),
                'contact_email' => $quote->email,
                'contact_phone' => $quote->phone,
                'company_name' => $quote->company_name,
                'category' => 'Connect',
                'tags' => ['Connect', 'Enterprise', 'Aanmelding'],
                'stage' => 'lead',
                'status' => 'open',
                'position' => (int) CrmDeal::where('stage', 'lead')->max('position') + 1,
                'goal' => 'Connect enterprise samenwerking bespreken',
                'notes' => $notes,
            ]);
            $deal->activities()->create([
                'type' => 'system',
                'body' => $notes,
                'meta' => ['connect_quote_request_id' => $quote->id],
                'happened_at' => now(),
            ]);
            return [$quote, $deal];
        });

        AppNotification::notifyAdmins(
            'admin.connect',
            'Nieuwe Connect-aanmelding',
            $quote->contact_name.' — '.$quote->company_name,
            ['action_url' => '/sales', 'deal_id' => $deal->id, 'connect_quote_request_id' => $quote->id],
        );

        return $quote;
    }
}
