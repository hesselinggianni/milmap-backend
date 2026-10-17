<?php
namespace App\Services;
use App\Models\{ConnectOrder, ConnectOrderInvoice, ConnectOrganization, ConnectPendingAddition};
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
            'vat_method'=>$taxRateId ? 'tax_rate' : 'gross_price','tax_rate_id'=>$taxRateId,'terms'=>[1,6,12]];
    }
    /**
     * Bijbestelling die meeloopt met de lopende licentie: vanaf nu tot de eerstvolgende einddatum van een
     * betaalde, lopende licentie betaal je pro rata (per hele dag); daarna verlengt alles samen.
     * null = geen lopende licentie, of die ligt verder weg dan één termijn: dan een gewone bestelling.
     * @return array{ends_at:Carbon,remaining_days:int,term_days:int,unit_term_cents:int}|null
     */
    public function coterm(ConnectOrganization $org, int $months, int $unitMonthly): ?array {
        $end=$org->licenses()->where('is_trial',false)->where('starts_at','<=',now())->where('ends_at','>',now())->min('ends_at');
        if (!$end) return null;
        $end=Carbon::parse($end)->utc();
        $remaining=(int)ceil(now()->floatDiffInDays($end,true));
        $termDays=max(1,(int)round($end->copy()->subMonthsNoOverflow($months)->floatDiffInDays($end,true)));
        // Minder dan een dag over, of verder weg dan één termijn: geen meeloop-bestelling.
        if ($remaining<1 || $remaining>$termDays+1) return null;
        $remaining=min($remaining,$termDays);
        return ['ends_at'=>$end,'remaining_days'=>$remaining,'term_days'=>$termDays,'unit_term_cents'=>(int)round($unitMonthly*$months*$remaining/$termDays)];
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
        $coterm=$this->coterm($org,(int)$data['term_months'],$quote['unit_monthly_cents']);
        // Meeloop-bestelling: nu pro rata tot de einddatum van de lopende licentie, daarna het volle bedrag per termijn.
        $amounts=$coterm
            ? self::amounts($coterm['unit_term_cents'],1,(int)$data['seats'],$quote['prices_include_vat'],$quote['vat_method'],$quote['vat_percent'])
            : self::amounts($quote['unit_monthly_cents'],(int)$data['term_months'],(int)$data['seats'],$quote['prices_include_vat'],$quote['vat_method'],$quote['vat_percent']);
        // Wat de klant zag (totaal incl. btw) moet gelijk zijn aan wat Stripe afrekent.
        abort_if((int)($data['expected_total_incl_cents'] ?? 0)!==$amounts['incl_cents'],409,'Het totaalbedrag is gewijzigd. Ververs de checkout en controleer het nieuwe bedrag.');
        $order=DB::transaction(function () use ($org,$actorId,$data,$quote,$amounts,$coterm) {
            $locked=ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            // De ingevulde factuurgegevens worden ook de gegevens van het account (volgende checkout en Connect-account).
            $this->storeDetails($locked,$data['billing_details']);
            $existing=ConnectOrder::where('organization_id',$org->id)->where('request_id',$data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->seats===(int)$data['seats'] && $existing->term_months===(int)$data['term_months'] && $existing->payment_method===$data['payment_method'] && (bool)$existing->auto_renew===(bool)($data['auto_renew'] ?? true),409,'Deze bestelreferentie hoort bij een andere bestelling.');
                // A retry must charge the price the caller accepted, including when Stripe was unavailable on its first attempt.
                abort_unless($existing->unit_monthly_cents===$quote['unit_monthly_cents'] && $existing->currency===$quote['currency']
                    && $existing->total_cents===$amounts['excl_cents'] && $existing->vat_cents===$amounts['vat_cents'] && $existing->total_incl_cents===$amounts['incl_cents']
                    && $existing->vat_percent===$quote['vat_percent'] && $existing->vat_method===$quote['vat_method'],409,'Het totaalbedrag is gewijzigd. Start een nieuwe bestelling en controleer het nieuwe bedrag.');
                $pendingId=ConnectPendingAddition::where('order_id',$existing->id)->value('id');
                abort_unless($pendingId===($data['pending_addition_id'] ?? null),409,'Deze bestelreferentie hoort bij een andere toevoeging.');
                return $existing;
            }
            $pending=!empty($data['pending_addition_id']) ? app(ConnectPendingAdditions::class)->assertAttachable($locked,$data['pending_addition_id'],(int)$data['seats']) : null;
            $order=ConnectOrder::create(['organization_id'=>$org->id,'ordered_by'=>$actorId,'request_id'=>$data['request_id'],
                'seats'=>$data['seats'],'term_months'=>$data['term_months'],'payment_method'=>$data['payment_method'],'auto_renew'=>(bool)($data['auto_renew'] ?? true),
                'unit_monthly_cents'=>$quote['unit_monthly_cents'],'total_cents'=>$amounts['excl_cents'],
                'vat_percent'=>$quote['vat_percent'],'vat_cents'=>$amounts['vat_cents'],'total_incl_cents'=>$amounts['incl_cents'],'vat_method'=>$quote['vat_method'],
                'currency'=>$quote['currency'],'billing_details'=>$data['billing_details'],'coterm_ends_at'=>$coterm['ends_at'] ?? null]);
            // Wat na betaling wordt toegevoegd, hoort bij deze bestelling (een nieuwe poging neemt het over).
            $pending?->update(['order_id'=>$order->id]);
            return $order;
        });
        $org->refresh();
        if ($order->stripe_checkout_id || ($order->stripe_subscription_id && in_array($order->status,['paid','cancelled'],true) && $order->invoices()->exists())) return $order->load('invoices');
        $stripe=$this->stripe();
        // A stable idempotency key also protects retries after Stripe accepted
        // a request but the network/DB write failed before the response arrived.
        // Parallel orders must share one Stripe customer, including when its first creation is still in flight.
        $org=DB::transaction(function () use ($org,$stripe) {
            $locked=ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (!$locked->stripe_customer_id) {
                $payload=$this->customerPayload($locked); $hash=hash('sha256',json_encode($payload));
                $customer=$stripe->customers->create($payload+['metadata'=>['connect_organization_id'=>(string)$locked->id]],['idempotency_key'=>'connect-customer-'.$locked->id.'-'.substr($hash,0,20)]);
                $locked->forceFill(['stripe_customer_id'=>$customer->id,'stripe_customer_details_hash'=>$hash])->save();
            } else $this->syncCustomer($locked,$stripe);
            return $locked;
        });
        $customerId=$org->stripe_customer_id;
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
        // Meeloop-bestelling: de volle termijn gaat pas in op de einddatum van de lopende licentie (anker, zonder
        // Stripe-verrekening); wat nu betaald wordt is één eenmalige regel met het pro-rata bedrag (zelfde btw-regels).
        $coterm=null; $once=null;
        if ($order->coterm_ends_at) {
            $anchor=$order->coterm_ends_at->getTimestamp();
            // Pro-rata bedrag per seat uit de bestelling zelf (zelfde btw-regels als de volle prijs hierboven):
            // met btw-tarief op een prijs excl. btw het bedrag excl. btw, anders het bedrag incl. btw.
            $perSeat=intdiv($withRate && !$quote['prices_include_vat'] ? $order->total_cents : $order->total_incl_cents,max(1,$order->seats));
            $onceItem=['price_data'=>['currency'=>strtolower($order->currency),'product'=>$priceData['product'],'unit_amount'=>$perSeat,'tax_behavior'=>$inclusive?'inclusive':'exclusive'],'quantity'=>$order->seats];
            if ($withRate) $onceItem['tax_rates']=[$quote['tax_rate_id']];
            $once=$onceItem; $coterm=$anchor;
        }
        if ($order->payment_method==='direct') {
            $embedded=!empty($data['embedded']) && $this->publishableKey();
            $session=$stripe->checkout->sessions->create(['mode'=>'subscription','customer'=>$customerId,'line_items'=>$once ? [$once,$item] : [$item],
                'billing_address_collection'=>'required','customer_update'=>['address'=>'auto','name'=>'auto'],
                'tax_id_collection'=>['enabled'=>true], 'locale'=>'auto', 'metadata'=>$metadata,'subscription_data'=>['metadata'=>$metadata]+($coterm ? ['billing_cycle_anchor'=>$coterm,'proration_behavior'=>'none'] : []),
            ]+($embedded ? [
                // Betaalvelden in onze eigen checkout (Embedded Checkout): geen Stripe-pagina, dus ook geen cancel_url.
                'ui_mode'=>'embedded',
                'return_url'=>rtrim(config('connect.url'),'/').'/licenties?checkout=success&order='.$order->id.'&session_id={CHECKOUT_SESSION_ID}',
            ] : [
                // Gehoste checkout (terugval): Stripe stuurt na betalen of annuleren terug naar Licenties.
                'success_url'=>rtrim(config('connect.url'),'/').'/licenties?checkout=success&order='.$order->id,
                'cancel_url'=>rtrim(config('connect.url'),'/').'/licenties?checkout=cancelled&order='.$order->id,
            ]),['idempotency_key'=>'connect-checkout-'.$order->id]);
            $order=$this->awaitingPayment($order,['stripe_checkout_id'=>$session->id,'checkout_url'=>$session->url]);
            // Stripe Checkout mag naam en adres van de klant overschrijven (customer_update auto): de volgende bestelling of wijziging stuurt de accountgegevens daarom opnieuw.
            $org->forceFill(['stripe_customer_details_hash'=>null])->save();
        } else {
            $subscription=$order->stripe_subscription_id ? $stripe->subscriptions->retrieve($order->stripe_subscription_id,['expand'=>['latest_invoice']]) : $stripe->subscriptions->create(['customer'=>$customerId,'items'=>[$item],'collection_method'=>'send_invoice','days_until_due'=>14]+($coterm ? ['billing_cycle_anchor'=>$coterm,'proration_behavior'=>'none','add_invoice_items'=>[$once]] : [])+[
                'metadata'=>$metadata,'expand'=>['latest_invoice']],['idempotency_key'=>'connect-invoice-subscription-'.$order->id]);
            if (!$order->auto_renew && !$order->stripe_subscription_id) $stripe->subscriptions->update($subscription->id,['cancel_at_period_end'=>true],['idempotency_key'=>'connect-norenew-'.$order->id]);
            $order=$this->awaitingPayment($order,['stripe_subscription_id'=>$subscription->id]);
            $invoice=$subscription->latest_invoice;
            if (is_string($invoice)) $invoice=$stripe->invoices->retrieve($invoice,[]);
            if ($invoice->status==='draft') $invoice=$stripe->invoices->finalizeInvoice($invoice->id,[],['idempotency_key'=>'connect-finalize-'.$invoice->id]);
            if ($invoice->status==='open') $invoice=$stripe->invoices->sendInvoice($invoice->id,[],['idempotency_key'=>'connect-send-'.$invoice->id]);
            $this->recordInvoice($order,$invoice);
        }
        app(ConnectManagement::class)->audit($org->id,'order.created','ConnectOrder',$order->id,['seats'=>$order->seats,'term_months'=>$order->term_months,'payment_method'=>$order->payment_method]);
        return $order->fresh()->load('invoices');
    }
    /** A webhook may arrive before the request that created its Stripe object returns. */
    private function awaitingPayment(ConnectOrder $order, array $attributes): ConnectOrder {
        return DB::transaction(function () use ($order,$attributes) {
            $locked=ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status==='pending') $attributes['status']='awaiting_payment';
            $locked->update($attributes);
            return $locked;
        });
    }
    /** Publishable key voor Stripe.js; leeg = Embedded Checkout uit (gehoste checkout als terugval). */
    public function publishableKey(): ?string { return config('billing.stripe_key') ?: null; }
    /** client_secret van een lopende Embedded Checkout-sessie, ook bij een herhaald verzoek (null = gehost of al afgerond). */
    public function embeddedSecret(ConnectOrder $order): ?string {
        if ($order->checkout_url || !$order->stripe_checkout_id || $order->status!=='awaiting_payment' || !$this->publishableKey()) return null;
        try { $s=$this->stripe()->checkout->sessions->retrieve($order->stripe_checkout_id,[]); return $s->status==='open' ? ($s->client_secret ?? null) : null; }
        catch (\Throwable $e) { Log::warning('Connect embedded secret ophalen mislukt',['order'=>$order->id,'error'=>$e->getMessage()]); return null; }
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
        return DB::transaction(function () use ($order,$invoice) {
            // Also serialize invoice writes from the original checkout request with incoming webhooks.
            ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = ConnectOrderInvoice::where('stripe_invoice_id',$invoice->id)->first();
            abort_unless(!$existing || $existing->order_id===$order->id,409);
            if ($existing?->status === 'paid' && ($invoice->status ?? '') !== 'paid') return $existing;
            $period=$this->invoicePeriod($invoice);
            return ConnectOrderInvoice::updateOrCreate(['stripe_invoice_id'=>$invoice->id],[
                'order_id'=>$order->id,'number'=>$invoice->number ?? null,'status'=>$invoice->status ?? 'open',
                'total_cents'=>max(0,(int)($invoice->total ?? 0)),'currency'=>strtoupper($invoice->currency ?? $order->currency),
                'hosted_invoice_url'=>$invoice->hosted_invoice_url ?? null,'invoice_pdf'=>$invoice->invoice_pdf ?? null,
                'period_start'=>isset($period->start) ? Carbon::createFromTimestampUTC($period->start) : null,
                'period_end'=>isset($period->end) ? Carbon::createFromTimestampUTC($period->end) : null,
                'paid_at'=>($invoice->status ?? '')==='paid' ? Carbon::createFromTimestampUTC($invoice->status_transitions->paid_at ?? now()->timestamp) : null,
            ]);
        });
    }
    private function stripeId($value): ?string {
        return is_string($value) ? $value : ($value->id ?? null);
    }
    /** Pending invoice items and prorations precede the recurring seat line in Stripe invoices. */
    private function invoicePeriod(object $invoice): ?object {
        $subscriptionId=$this->stripeId($invoice->subscription ?? $invoice->parent->subscription_details->subscription ?? null);
        $lines=[];
        foreach ($invoice->lines->data ?? [] as $line) {
            $details=$line->parent->subscription_item_details ?? null;
            if (!$details && ($line->type ?? '')!=='subscription' && !isset($line->subscription_item)) continue;
            if ($details->proration ?? $line->proration ?? false) continue;
            $lineSubscription=$this->stripeId($details->subscription ?? $line->subscription ?? null);
            if ($lineSubscription && $lineSubscription!==$subscriptionId) continue;
            if (isset($line->period)) $lines[]=$line->period;
        }
        return count($lines)===1 ? $lines[0] : null;
    }
    /** Called only after BillingController has verified the Stripe signature. */
    public function webhook(string $type, object $object): bool {
        if (!in_array($type,['checkout.session.completed','invoice.paid','invoice.finalized','invoice.payment_failed','invoice.voided','customer.subscription.updated','customer.subscription.deleted'])) return false;
        $orderId=$object->metadata->connect_order_id ?? $object->subscription_details->metadata->connect_order_id ?? $object->parent->subscription_details->metadata->connect_order_id ?? null;
        $subscriptionId=$this->stripeId(str_starts_with($type,'customer.subscription.') ? $object->id : ($object->subscription ?? $object->parent->subscription_details->subscription ?? null));
        $order=$orderId ? ConnectOrder::find($orderId) : ($subscriptionId ? ConnectOrder::where('stripe_subscription_id',$subscriptionId)->first() : null);
        if (!$order && $subscriptionId && str_starts_with($type,'invoice.')) {
            $sub=$this->stripe()->subscriptions->retrieve($subscriptionId,[]);
            if ($sub->metadata->connect_order_id ?? null) $order=ConnectOrder::find($sub->metadata->connect_order_id);
        }
        if (!$order) return false;
        $organizationId=$object->metadata->connect_organization_id ?? $object->subscription_details->metadata->connect_organization_id ?? $object->parent->subscription_details->metadata->connect_organization_id ?? null;
        abort_unless(!$organizationId || (string)$organizationId===(string)$order->organization_id,409);
        $customerId=$this->stripeId($object->customer ?? null);
        abort_unless(!$customerId || $customerId===$order->organization->stripe_customer_id,409);
        if ($type==='checkout.session.completed') {
            DB::transaction(function () use ($order,$object,$subscriptionId) {
                $order=ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless(!$order->stripe_checkout_id || $order->stripe_checkout_id===$object->id,409);
                abort_unless($subscriptionId && (!$order->stripe_subscription_id || $order->stripe_subscription_id===$subscriptionId),409);
                $attributes=['stripe_checkout_id'=>$object->id,'stripe_subscription_id'=>$subscriptionId];
                if ($order->status==='pending') $attributes['status']='awaiting_payment';
                $order->update($attributes);
            });
            // De klant kan in Checkout andere naam/adresgegevens hebben ingevuld: accountgegevens bij de volgende bestelling of wijziging opnieuw sturen.
            ConnectOrganization::whereKey($order->organization_id)->update(['stripe_customer_details_hash'=>null]);
            if (!$order->auto_renew && $subscriptionId) $this->stripe()->subscriptions->update($subscriptionId,['cancel_at_period_end'=>true],['idempotency_key'=>'connect-norenew-'.$order->id]);
            return true; // Checkout completion alone never grants capacity.
        }
        if (str_starts_with($type,'invoice.')) {
            DB::transaction(function () use ($order,$object,$type,$subscriptionId) {
                $order=ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless($subscriptionId && (!$order->stripe_subscription_id || $order->stripe_subscription_id===$subscriptionId),409);
                abort_unless(strtoupper($object->currency ?? '')===$order->currency,422,'Factuurvaluta komt niet overeen.');
                $order->update(['stripe_subscription_id'=>$subscriptionId]);
                $invoice=$this->recordInvoice($order,$object);
                $hasLaterInvoice=$invoice->period_start && $order->invoices()->where('period_start','>',$invoice->period_start)->exists();
                if ($type==='invoice.paid' && ($object->status ?? '')==='paid') {
                    // Meeloop-bestelling: de eerste factuur (vóór de einddatum van de lopende licentie) dekt nu t/m die datum;
                    // de periode op die factuur is dan niet bruikbaar. Verlengingen vanaf die datum zijn gewone periodes.
                    $coterm=$order->coterm_ends_at && $invoice->paid_at && $invoice->paid_at->lt($order->coterm_ends_at) && (!$invoice->period_start || $invoice->period_start->lt($order->coterm_ends_at));
                    if ($coterm) {
                        $periodStart=$invoice->paid_at; $periodEnd=$order->coterm_ends_at;
                    } else {
                        abort_unless($invoice->period_start && $invoice->period_end && $invoice->period_end->gt($invoice->period_start),422,'Factuurperiode ontbreekt.');
                        $periodStart=$invoice->period_start; $periodEnd=$invoice->period_end;
                    }
                    // A finalized invoice may already say "paid" before invoice.paid is delivered.
                    // Capacity, rather than invoice status, is the provisioning idempotency marker.
                    $provisioned=$order->organization->licenses()->where('source_reference','stripe-invoice:'.$object->id)->exists();
                    app(ConnectLicensing::class)->add($order->organization,[
                        'name'=>'Connect · '.$order->seats.' seats · '.$order->term_months.' maanden','seat_count'=>$order->seats,
                        'monthly_price_cents'=>$order->unit_monthly_cents*$order->seats,'currency'=>$order->currency,
                        'starts_at'=>$periodStart,'ends_at'=>$periodEnd,'source_reference'=>'stripe-invoice:'.$object->id,
                    ]);
                    if (!$hasLaterInvoice && !in_array($order->status,['cancellation_requested','cancelled'],true)) $order->update(['status'=>'paid']);
                    if (!$provisioned) app(ConnectManagement::class)->audit($order->organization_id,'invoice.paid','ConnectOrder',$order->id,['invoice_id'=>$object->id]);
                } elseif ($type==='invoice.payment_failed' && $invoice->status!=='paid' && !$hasLaterInvoice && !in_array($order->status,['cancellation_requested','cancelled'],true)) $order->update(['status'=>'payment_failed']);
            });
            // Seats zijn er nu: wat in Connect op deze betaling wachtte, wordt toegevoegd (mislukken blokkeert de webhook niet).
            if ($type==='invoice.paid' && ($object->status ?? '')==='paid') {
                try { app(ConnectPendingAdditions::class)->processForOrder($order); } catch (\Throwable $e) { report($e); }
            }
            return true;
        }
        if (str_starts_with($type,'customer.subscription.')) {
            DB::transaction(function () use ($order,$object,$subscriptionId,$type) {
                $order=ConnectOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless(!$order->stripe_subscription_id || $order->stripe_subscription_id===$subscriptionId,409);
                if ($type==='customer.subscription.deleted') $order->update(['status'=>'cancelled','stripe_subscription_id'=>$subscriptionId]);
                elseif (($object->cancel_at_period_end ?? false) && $order->status!=='cancelled') $order->update(['status'=>'cancellation_requested','stripe_subscription_id'=>$subscriptionId]);
            });
            return true;
        }
        return false;
    }
}
