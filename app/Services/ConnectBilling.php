<?php
namespace App\Services;
use App\Models\{ConnectOrder, ConnectOrderInvoice, ConnectOrganization};
use App\Http\Controllers\AdminBillingController;
use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stripe\StripeClient;
class ConnectBilling {
    protected function stripe(): StripeClient { abort_unless(config('billing.stripe_secret'),503,'Online betalen is nog niet beschikbaar. Probeer het later opnieuw.'); return new StripeClient(['api_key'=>config('billing.stripe_secret')]); }
    public function quote(?ConnectOrganization $org = null): array {
        // Een klantspecifieke Stripe-prijs (admin koppelt die aan de organisatie) gaat voor de algemene seatprijs.
        $priceId = $org?->stripe_price_id ?: (AdminBillingController::effectiveMap()['connect_seat_monthly'] ?? null);
        abort_unless($priceId,503,'Er is nog geen Connect-prijs gekoppeld aan deze organisatie.');
        $price=$this->stripe()->prices->retrieve($priceId,[]);
        abort_unless($price->active && $price->type==='recurring' && $price->recurring->interval==='month' && $price->recurring->interval_count===1 && $price->unit_amount>0,503,'Connect vereist een actieve vaste maandprijs per seat.');
        // Stripe-prijs 'inclusive' = bedrag incl. btw; 'exclusive'/'unspecified' = zakelijke prijs excl. btw.
        $inclusive=($price->tax_behavior ?? 'unspecified')==='inclusive';
        $percent=$this->vatPercent();
        $taxRateId=$this->taxRateId($inclusive,$percent);
        return ['price_id'=>$priceId,'product'=>$price->product,'unit_monthly_cents'=>$price->unit_amount,'currency'=>strtoupper($price->currency),
            'tax_behavior'=>$price->tax_behavior ?? 'unspecified','prices_include_vat'=>$inclusive,'vat_percent'=>$percent,
            'vat_method'=>$taxRateId ? 'tax_rate' : 'gross_price','tax_rate_id'=>$taxRateId,'terms'=>[1,12]];
    }
    public function vatPercent(): int { return max(0, min(100, (int) config('billing.vat_percent', 21))); }
    /** Afronden van een positieve breuk n/d op hele centen (half naar boven) — identiek aan de checkout-frontend. */
    private static function roundDiv(int $n, int $d): int { return intdiv(2*$n+$d, 2*$d); }
    /**
     * Bedragen voor één bestelling, berekend zoals Stripe ze afrekent:
     *  - prijs incl. btw: totaal = prijs × seats; btw zit erin;
     *  - prijs excl. btw + btw-tarief in Stripe: btw = 21% over het regeltotaal;
     *  - prijs excl. btw zonder btw-tarief (sleutel mist rechten): prijs per seat incl. btw afgerond, × seats.
     * @return array{unit_term_cents:int,unit_term_incl_cents:int,excl_cents:int,vat_cents:int,incl_cents:int}
     */
    public static function amounts(int $unitMonthly, int $months, int $seats, bool $inclusive, string $method, int $percent): array {
        $unitTerm=$unitMonthly*$months;
        if ($inclusive) {
            $incl=$unitTerm*$seats; $vat=self::roundDiv($incl*$percent,100+$percent);
            return ['unit_term_cents'=>$unitTerm,'unit_term_incl_cents'=>$unitTerm,'excl_cents'=>$incl-$vat,'vat_cents'=>$vat,'incl_cents'=>$incl];
        }
        if ($method==='tax_rate') {
            $excl=$unitTerm*$seats; $vat=self::roundDiv($excl*$percent,100);
            return ['unit_term_cents'=>$unitTerm,'unit_term_incl_cents'=>self::roundDiv($unitTerm*(100+$percent),100),'excl_cents'=>$excl,'vat_cents'=>$vat,'incl_cents'=>$excl+$vat];
        }
        $unitIncl=self::roundDiv($unitTerm*(100+$percent),100); $incl=$unitIncl*$seats; $vat=self::roundDiv($incl*$percent,100+$percent);
        return ['unit_term_cents'=>$unitTerm,'unit_term_incl_cents'=>$unitIncl,'excl_cents'=>$incl-$vat,'vat_cents'=>$vat,'incl_cents'=>$incl];
    }
    /**
     * Btw-tarief in Stripe (eenmalig aangemaakt en bewaard), zodat de factuur de btw apart vermeldt.
     * Heeft de API-sleutel geen rechten op tax rates, dan null: het bedrag incl. btw wordt dan in de prijs verwerkt.
     */
    protected function taxRateId(bool $inclusive, int $percent): ?string {
        if ($percent<=0) return null;
        $key=($inclusive?'inclusive':'exclusive').'_'.$percent;
        $saved=Setting::get('connect_vat_tax_rates',[]);
        if (is_array($saved) && !empty($saved[$key])) return $saved[$key];
        if (Cache::get('connect_vat_tax_rate_unavailable')) return null;
        try {
            $rate=$this->stripe()->taxRates->create(['display_name'=>'Btw','description'=>"Nederlandse btw {$percent}% (MilMap Connect)",'percentage'=>$percent,
                'inclusive'=>$inclusive,'country'=>'NL','jurisdiction'=>'NL','tax_type'=>'vat'],['idempotency_key'=>'connect-vat-'.$key]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('Connect: btw-tarief kon niet in Stripe worden aangemaakt; btw wordt in de prijs verwerkt',['error'=>$e->getMessage()]);
            Cache::put('connect_vat_tax_rate_unavailable',true,now()->addMinutes(10));
            return null;
        }
        Setting::put('connect_vat_tax_rates',array_merge(is_array($saved)?$saved:[],[$key=>$rate->id]));
        return $rate->id;
    }
    public function order(ConnectOrganization $org, int $actorId, array $data): ConnectOrder {
        try {
            return $this->placeOrder($org,$actorId,$data);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Connect-bestelling mislukt bij Stripe',['organization'=>$org->id,'error'=>$e->getMessage()]);
            abort(422,'De bestelling kon niet worden verwerkt. Controleer je gegevens en probeer het opnieuw.');
        }
    }
    protected function placeOrder(ConnectOrganization $org, int $actorId, array $data): ConnectOrder {
        $quote=$this->quote($org);
        abort_unless((int)$data['expected_unit_monthly_cents']===$quote['unit_monthly_cents'] && $data['expected_currency']===$quote['currency'],409,'De seatprijs is gewijzigd. Ververs de checkout en controleer het nieuwe bedrag.');
        $amounts=self::amounts($quote['unit_monthly_cents'],(int)$data['term_months'],(int)$data['seats'],$quote['prices_include_vat'],$quote['vat_method'],$quote['vat_percent']);
        // Wat de klant zag (totaal incl. btw) moet gelijk zijn aan wat Stripe afrekent.
        abort_if((int)($data['expected_total_incl_cents'] ?? 0)!==$amounts['incl_cents'],409,'Het totaalbedrag is gewijzigd. Ververs de checkout en controleer het nieuwe bedrag.');
        $order=DB::transaction(function () use ($org,$actorId,$data,$quote,$amounts) {
            $locked=ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            // De ingevulde factuurgegevens worden ook de gegevens van het account (volgende checkout en Connect-account).
            $this->storeDetails($locked,$data['billing_details']);
            $existing=ConnectOrder::where('organization_id',$org->id)->where('request_id',$data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->seats===$data['seats'] && $existing->term_months===$data['term_months'] && $existing->payment_method===$data['payment_method'] && (bool)$existing->auto_renew===(bool)($data['auto_renew'] ?? true),409,'Deze bestelreferentie hoort bij een andere bestelling.');
                return $existing;
            }
            return ConnectOrder::create(['organization_id'=>$org->id,'ordered_by'=>$actorId,'request_id'=>$data['request_id'],
                'seats'=>$data['seats'],'term_months'=>$data['term_months'],'payment_method'=>$data['payment_method'],'auto_renew'=>(bool)($data['auto_renew'] ?? true),
                'unit_monthly_cents'=>$quote['unit_monthly_cents'],'total_cents'=>$amounts['excl_cents'],
                'vat_percent'=>$quote['vat_percent'],'vat_cents'=>$amounts['vat_cents'],'total_incl_cents'=>$amounts['incl_cents'],'vat_method'=>$quote['vat_method'],
                'currency'=>$quote['currency'],'billing_details'=>$data['billing_details']]);
        });
        $org->refresh();
        if ($order->stripe_checkout_id || ($order->stripe_subscription_id && $order->invoices()->exists())) return $order->load('invoices');
        // Herhaalde aanvraag terwijl de btw-afhandeling intussen wijzigde: niet afrekenen met een ander totaal.
        abort_if($order->vat_method!==$quote['vat_method'],409,'Het totaalbedrag is gewijzigd. Ververs de checkout en controleer het nieuwe bedrag.');
        $stripe=$this->stripe();
        // A stable idempotency key also protects retries after Stripe accepted
        // a request but the network/DB write failed before the response arrived.
        $customerId=$org->stripe_customer_id;
        if (!$customerId) {
            $payload=$this->customerPayload($org); $hash=hash('sha256',json_encode($payload));
            $customer=$stripe->customers->create($payload+['metadata'=>['connect_organization_id'=>(string)$org->id]],['idempotency_key'=>'connect-customer-'.$org->id.'-'.substr($hash,0,20)]);
            $customerId=$customer->id; $org->forceFill(['stripe_customer_id'=>$customerId,'stripe_customer_details_hash'=>$hash])->save();
        } else $this->syncCustomer($org,$stripe);
        $metadata=['connect_order_id'=>(string)$order->id,'connect_organization_id'=>(string)$org->id,'seats'=>(string)$order->seats,'term_months'=>(string)$order->term_months,'auto_renew'=>$order->auto_renew?'1':'0'];
        // Btw: met een btw-tarief rekent Stripe de btw zelf (en toont die op de factuur); zonder tarief
        // gaat de prijs per seat incl. btw mee. In beide gevallen is het afgerekende totaal = total_incl_cents.
        $withRate=$order->vat_method==='tax_rate' && $quote['tax_rate_id'];
        $inclusive=$quote['prices_include_vat'] || !$withRate;
        $unitAmount=$quote['prices_include_vat'] || $withRate ? $order->unit_monthly_cents*$order->term_months
            : self::amounts($order->unit_monthly_cents,$order->term_months,$order->seats,false,'gross_price',$order->vat_percent)['unit_term_incl_cents'];
        $priceData=['currency'=>strtolower($order->currency),'product'=>$this->productFor($org,$quote['product']),'unit_amount'=>$unitAmount,'tax_behavior'=>$inclusive?'inclusive':'exclusive',
            'recurring'=>$order->term_months===12 ? ['interval'=>'year','interval_count'=>1] : ['interval'=>'month','interval_count'=>$order->term_months]];
        $item=['price_data'=>$priceData,'quantity'=>$order->seats];
        if ($withRate) $item['tax_rates']=[$quote['tax_rate_id']];
        if ($order->payment_method==='direct') {
            $session=$stripe->checkout->sessions->create(['mode'=>'subscription','customer'=>$customerId,'line_items'=>[$item],
                'billing_address_collection'=>'required','customer_update'=>['address'=>'auto','name'=>'auto'],
                'tax_id_collection'=>['enabled'=>true], 'locale'=>'auto', 'metadata'=>$metadata,'subscription_data'=>['metadata'=>$metadata],
                'success_url'=>rtrim(config('connect.url'),'/').'/?checkout=success&order='.$order->id,
                'cancel_url'=>rtrim(config('app.frontend_url'),'/').'/checkout/connect?org='.$org->uuid.'&order='.$order->id,
            ],['idempotency_key'=>'connect-checkout-'.$order->id]);
            $order->update(['stripe_checkout_id'=>$session->id,'checkout_url'=>$session->url,'status'=>'awaiting_payment']);
            // Stripe Checkout mag naam en adres van de klant overschrijven (customer_update auto): de volgende bestelling of wijziging stuurt de accountgegevens daarom opnieuw.
            $org->forceFill(['stripe_customer_details_hash'=>null])->save();
        } else {
            $subscription=$order->stripe_subscription_id ? $stripe->subscriptions->retrieve($order->stripe_subscription_id,['expand'=>['latest_invoice']]) : $stripe->subscriptions->create(['customer'=>$customerId,'items'=>[$item],'collection_method'=>'send_invoice','days_until_due'=>14,
                'metadata'=>$metadata,'expand'=>['latest_invoice']],['idempotency_key'=>'connect-invoice-subscription-'.$order->id]);
            if (!$order->auto_renew && !$order->stripe_subscription_id) $stripe->subscriptions->update($subscription->id,['cancel_at_period_end'=>true],['idempotency_key'=>'connect-norenew-'.$order->id]);
            $order->update(['stripe_subscription_id'=>$subscription->id,'status'=>'awaiting_payment']);
            $invoice=$subscription->latest_invoice;
            if (is_string($invoice)) $invoice=$stripe->invoices->retrieve($invoice,[]);
            if ($invoice->status==='draft') $invoice=$stripe->invoices->finalizeInvoice($invoice->id,[],['idempotency_key'=>'connect-finalize-'.$order->id]);
            if ($invoice->status==='open') $invoice=$stripe->invoices->sendInvoice($invoice->id,[],['idempotency_key'=>'connect-send-'.$order->id]);
            $this->recordInvoice($order,$invoice);
        }
        app(ConnectManagement::class)->audit($org->id,'order.created','ConnectOrder',$order->id,['seats'=>$order->seats,'term_months'=>$order->term_months,'payment_method'=>$order->payment_method]);
        return $order->fresh()->load('invoices');
    }
    /**
     * Factuurgegevens vanuit het Connect-account wijzigen. Opslaan en de Stripe-klant bijwerken gebeuren samen:
     * weigert Stripe de gegevens, dan blijft alles ongewijzigd en krijgt de beheerder een nette 422.
     */
    public function updateDetails(ConnectOrganization $org, array $details): ConnectOrganization {
        try {
            $locked=DB::transaction(function () use ($org,$details) {
                $locked=ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $this->storeDetails($locked,$details);
                $this->syncCustomer($locked);
                return $locked;
            });
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Connect-factuurgegevens konden niet naar Stripe',['organization'=>$org->id,'error'=>$e->getMessage()]);
            abort(422,'De factuurgegevens konden niet worden bijgewerkt. Controleer je gegevens en probeer het opnieuw.');
        }
        $org->setRawAttributes($locked->getAttributes(),true);
        return $org;
    }
    /** Ingevulde factuurgegevens (ook gedeeltelijk) op de organisatie zetten; het e-mailadres staat in billing_email. */
    protected function storeDetails(ConnectOrganization $org, array $details): void {
        $clean=fn ($v) => ($v=trim((string)$v))==='' ? null : $v;
        $current=$org->billing_details ?: [];
        $company=array_key_exists('company_name',$details) ? $clean($details['company_name']) : ($current['company_name'] ?? null);
        $a=$details['address'] ?? null;
        $address=is_array($a) ? ['line1'=>$clean($a['line1'] ?? ''),'line2'=>$clean($a['line2'] ?? ''),'postal_code'=>$clean($a['postal_code'] ?? ''),'city'=>$clean($a['city'] ?? ''),'country'=>($c=$clean($a['country'] ?? ''))===null ? null : strtoupper($c)] : ($current['address'] ?? null);
        $org->forceFill(['billing_email'=>array_key_exists('email',$details) ? $clean($details['email']) : $org->billing_email,
            'billing_details'=>$company===null && $address===null ? null : ['company_name'=>$company,'address'=>$address]])->save();
    }
    /** Wat de Stripe-klant krijgt: alleen opgeslagen velden, zodat niets bij Stripe wordt leeggemaakt dat hier nooit is ingevuld. */
    protected function customerPayload(ConnectOrganization $org): array {
        $d=$org->billing_details ?: []; $a=$d['address'] ?? null;
        return array_filter(['name'=>$d['company_name'] ?? null,'email'=>$org->billing_email,
            'address'=>$a ? ['line1'=>$a['line1'],'line2'=>$a['line2'] ?? '','postal_code'=>$a['postal_code'],'city'=>$a['city'],'country'=>$a['country']] : null],fn ($v) => $v!==null && $v!=='');
    }
    /**
     * Stripe-klant alleen bijwerken als de gegevens afwijken van wat er laatst naartoe is gestuurd (hash op de organisatie).
     * Elke update krijgt een nieuwe idempotency-sleutel: bijwerken met dezelfde gegevens is vanzelf veilig, en een hergebruikte
     * sleutel zou Stripe een eerder antwoord laten herhalen zonder de klant bij te werken (bijv. na A→B→A of een herhaalde bestelling).
     */
    protected function syncCustomer(ConnectOrganization $org, ?StripeClient $stripe = null): void {
        if (!$org->stripe_customer_id) return;
        $payload=$this->customerPayload($org); if (!$payload) return;
        $hash=hash('sha256',json_encode($payload));
        if (hash_equals((string)$org->stripe_customer_details_hash,$hash)) return;
        ($stripe ?? $this->stripe())->customers->update($org->stripe_customer_id,$payload,['idempotency_key'=>'connect-customer-sync-'.$org->id.'-'.Str::uuid()]);
        $org->forceFill(['stripe_customer_details_hash'=>$hash])->save();
    }
    /** Eigen Stripe-product per klant (custom abonnement), eenmalig via de API aangemaakt. */
    protected function productFor(ConnectOrganization $org, string $fallback): string {
        if ($org->stripe_product_id) return $org->stripe_product_id;
        try {
            $product=$this->stripe()->products->create(['name'=>'MilMap Connect — '.$org->name,'description'=>'Connect-seats (accounts en apparaten) voor '.$org->name,
            'metadata'=>['connect_organization_id'=>(string)$org->id,'connect_organization_uuid'=>(string)$org->uuid]],['idempotency_key'=>'connect-product-'.$org->id.'-'.substr(md5($org->name),0,10)]);
        } catch (\Stripe\Exception\PermissionException $e) {
            // Sleutel zonder 'product_write': bestellen moet blijven werken, dan onder het product van de seatprijs.
            Log::warning('Connect: eigen Stripe-product kon niet worden aangemaakt; seatproduct gebruikt',['organization'=>$org->id,'error'=>$e->getMessage()]);
            return $fallback;
        }
        $org->forceFill(['stripe_product_id'=>$product->id])->save();
        return $product->id;
    }
    public function cancel(ConnectOrder $order): void {
        abort_unless($order->stripe_subscription_id,422,'Er is nog geen actief abonnement bij deze bestelling.');
        $this->stripe()->subscriptions->update($order->stripe_subscription_id,['cancel_at_period_end'=>true],['idempotency_key'=>'connect-cancel-'.$order->id]);
        $order->update(['status'=>'cancellation_requested']);
        app(ConnectManagement::class)->audit($order->organization_id,'order.renewal.cancelled','ConnectOrder',$order->id);
    }
    private function recordInvoice(ConnectOrder $order, object $invoice): ConnectOrderInvoice {
        $existing = ConnectOrderInvoice::where('stripe_invoice_id',$invoice->id)->first();
        if ($existing?->status === 'paid' && ($invoice->status ?? '') !== 'paid') return $existing;
        $period=$invoice->lines->data[0]->period ?? null;
        return ConnectOrderInvoice::updateOrCreate(['stripe_invoice_id'=>$invoice->id],[
            'order_id'=>$order->id,'number'=>$invoice->number ?? null,'status'=>$invoice->status ?? 'open',
            'total_cents'=>max(0,(int)($invoice->total ?? 0)),'currency'=>strtoupper($invoice->currency ?? $order->currency),
            'hosted_invoice_url'=>$invoice->hosted_invoice_url ?? null,'invoice_pdf'=>$invoice->invoice_pdf ?? null,
            'period_start'=>isset($period->start) ? Carbon::createFromTimestampUTC($period->start) : null,
            'period_end'=>isset($period->end) ? Carbon::createFromTimestampUTC($period->end) : null,
            'paid_at'=>($invoice->status ?? '')==='paid' ? Carbon::createFromTimestampUTC($invoice->status_transitions->paid_at ?? now()->timestamp) : null,
        ]);
    }
    /** Called only after BillingController has verified the Stripe signature. */
    public function webhook(string $type, object $object): bool {
        if (!in_array($type,['checkout.session.completed','invoice.paid','invoice.finalized','invoice.payment_failed','invoice.voided','customer.subscription.updated','customer.subscription.deleted'])) return false;
        $orderId=$object->metadata->connect_order_id ?? $object->subscription_details->metadata->connect_order_id ?? $object->parent->subscription_details->metadata->connect_order_id ?? null;
        $subscriptionId=$object->subscription ?? $object->parent->subscription_details->subscription ?? null;
        if (is_object($subscriptionId)) $subscriptionId=$subscriptionId->id;
        $order=$orderId ? ConnectOrder::find($orderId) : ($subscriptionId ? ConnectOrder::where('stripe_subscription_id',$subscriptionId)->first() : null);
        if (!$order && $subscriptionId && str_starts_with($type,'invoice.')) {
            $sub=$this->stripe()->subscriptions->retrieve($subscriptionId,[]);
            if ($sub->metadata->connect_order_id ?? null) $order=ConnectOrder::find($sub->metadata->connect_order_id);
        }
        if (!$order) return false;
        if ($type==='checkout.session.completed') {
            abort_unless(!$order->stripe_checkout_id || $order->stripe_checkout_id===$object->id,409);
            $order->update(['stripe_checkout_id'=>$object->id,'stripe_subscription_id'=>$subscriptionId,'status'=>$order->status==='paid' ? 'paid' : 'awaiting_payment']);
            // De klant kan in Checkout andere naam/adresgegevens hebben ingevuld: accountgegevens bij de volgende bestelling of wijziging opnieuw sturen.
            ConnectOrganization::whereKey($order->organization_id)->update(['stripe_customer_details_hash'=>null]);
            if (!$order->auto_renew && $subscriptionId) $this->stripe()->subscriptions->update($subscriptionId,['cancel_at_period_end'=>true],['idempotency_key'=>'connect-norenew-'.$order->id]);
            return true; // Checkout completion alone never grants capacity.
        }
        if (str_starts_with($type,'invoice.')) {
            DB::transaction(function () use ($order,$object,$type,$subscriptionId) {
                $order=ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless(!$order->stripe_subscription_id || $order->stripe_subscription_id===$subscriptionId,409);
                $alreadyPaid = ConnectOrderInvoice::where('stripe_invoice_id',$object->id)->where('status','paid')->exists();
                $invoice=$this->recordInvoice($order,$object);
                if ($alreadyPaid && $type==='invoice.paid') return;
                if ($type==='invoice.paid' && ($object->status ?? '')==='paid') {
                    abort_unless($invoice->period_start && $invoice->period_end && $invoice->period_end->gt($invoice->period_start),422,'Factuurperiode ontbreekt.');
                    app(ConnectLicensing::class)->add($order->organization,[
                        'name'=>'Connect · '.$order->seats.' seats · '.$order->term_months.' maanden','seat_count'=>$order->seats,
                        'monthly_price_cents'=>$order->unit_monthly_cents*$order->seats,'currency'=>$order->currency,
                        'starts_at'=>$invoice->period_start,'ends_at'=>$invoice->period_end,'source_reference'=>'stripe-invoice:'.$object->id,
                    ]);
                    $order->update(['status'=>$order->status==='cancellation_requested' ? 'cancellation_requested' : 'paid','stripe_subscription_id'=>$subscriptionId]);
                    app(ConnectManagement::class)->audit($order->organization_id,'invoice.paid','ConnectOrder',$order->id,['invoice_id'=>$object->id]);
                } elseif ($type==='invoice.payment_failed') $order->update(['status'=>'payment_failed']);
            });
            return true;
        }
        if ($type==='customer.subscription.updated') {
            if ($object->cancel_at_period_end ?? false) $order->update(['status'=>'cancellation_requested']);
            return true;
        }
        if ($type==='customer.subscription.deleted') { $order->update(['status'=>'cancelled']); return true; }
        return false;
    }
}
