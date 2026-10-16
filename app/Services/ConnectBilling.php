<?php
namespace App\Services;
use App\Models\{ConnectOrder, ConnectOrderInvoice, ConnectOrganization};
use App\Http\Controllers\AdminBillingController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;
class ConnectBilling {
    protected function stripe(): StripeClient { abort_unless(config('billing.stripe_secret'),503,'Stripe is nog niet geconfigureerd.'); return new StripeClient(['api_key'=>config('billing.stripe_secret')]); }
    public function quote(?ConnectOrganization $org = null): array {
        // Een klantspecifieke Stripe-prijs (admin koppelt die aan de organisatie) gaat voor de algemene seatprijs.
        $priceId = $org?->stripe_price_id ?: (AdminBillingController::effectiveMap()['connect_seat_monthly'] ?? null);
        abort_unless($priceId,503,'Er is nog geen Connect-prijs gekoppeld aan deze organisatie.');
        $price=$this->stripe()->prices->retrieve($priceId,[]);
        abort_unless($price->active && $price->type==='recurring' && $price->recurring->interval==='month' && $price->recurring->interval_count===1 && $price->unit_amount>0,503,'Connect vereist een actieve vaste maandprijs per seat.');
        return ['price_id'=>$priceId,'product'=>$price->product,'unit_monthly_cents'=>$price->unit_amount,'currency'=>strtoupper($price->currency),'tax_behavior'=>$price->tax_behavior ?? 'unspecified','terms'=>[1,6,12]];
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
        $order=DB::transaction(function () use ($org,$actorId,$data,$quote) {
            ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $existing=ConnectOrder::where('organization_id',$org->id)->where('request_id',$data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->seats===$data['seats'] && $existing->term_months===$data['term_months'] && $existing->payment_method===$data['payment_method'] && (bool)$existing->auto_renew===(bool)($data['auto_renew'] ?? true),409,'Deze bestelreferentie hoort bij een andere bestelling.');
                return $existing;
            }
            return ConnectOrder::create(['organization_id'=>$org->id,'ordered_by'=>$actorId,'request_id'=>$data['request_id'],
                'seats'=>$data['seats'],'term_months'=>$data['term_months'],'payment_method'=>$data['payment_method'],'auto_renew'=>(bool)($data['auto_renew'] ?? true),
                'unit_monthly_cents'=>$quote['unit_monthly_cents'],'total_cents'=>$quote['unit_monthly_cents']*$data['seats']*$data['term_months'],
                'currency'=>$quote['currency'],'billing_details'=>$data['billing_details']]);
        });
        if ($order->stripe_checkout_id || ($order->stripe_subscription_id && $order->invoices()->exists())) return $order->load('invoices');
        $stripe=$this->stripe();
        // A stable idempotency key also protects retries after Stripe accepted
        // a request but the network/DB write failed before the response arrived.
        $customerId=$org->stripe_customer_id;
        if (!$customerId) {
            $customer=$stripe->customers->create(['name'=>$data['billing_details']['company_name'],'email'=>$data['billing_details']['email'],
                'address'=>$data['billing_details']['address'],'metadata'=>['connect_organization_id'=>(string)$org->id]],['idempotency_key'=>'connect-customer-'.$org->id.'-'.substr(md5(json_encode($data['billing_details'])),0,10)]);
            $customerId=$customer->id; $org->forceFill(['stripe_customer_id'=>$customerId])->save();
        }
        $stripe->customers->update($customerId,['name'=>$data['billing_details']['company_name'],'email'=>$data['billing_details']['email'],'address'=>$data['billing_details']['address']],['idempotency_key'=>'connect-customer-details-'.$order->id.'-'.substr(md5(json_encode($data['billing_details'])),0,10)]);
        $metadata=['connect_order_id'=>(string)$order->id,'connect_organization_id'=>(string)$org->id,'seats'=>(string)$order->seats,'term_months'=>(string)$order->term_months,'auto_renew'=>$order->auto_renew?'1':'0'];
        $priceData=['currency'=>strtolower($order->currency),'product'=>$this->productFor($org),'unit_amount'=>$order->unit_monthly_cents*$order->term_months,
            'recurring'=>$order->term_months===12 ? ['interval'=>'year','interval_count'=>1] : ['interval'=>'month','interval_count'=>$order->term_months]];
        if (in_array($quote['tax_behavior'],['inclusive','exclusive'])) $priceData['tax_behavior']=$quote['tax_behavior'];
        $item=['price_data'=>$priceData,'quantity'=>$order->seats];
        if ($order->payment_method==='direct') {
            $session=$stripe->checkout->sessions->create(['mode'=>'subscription','customer'=>$customerId,'line_items'=>[$item],
                'billing_address_collection'=>'required','customer_update'=>['address'=>'auto','name'=>'auto'],
                'tax_id_collection'=>['enabled'=>true], 'locale'=>'auto', 'metadata'=>$metadata,'subscription_data'=>['metadata'=>$metadata],
                'success_url'=>rtrim(config('connect.url'),'/').'/?checkout=success&order='.$order->id,
                'cancel_url'=>rtrim(config('app.frontend_url'),'/').'/checkout/connect?org='.$org->uuid.'&order='.$order->id,
            ],['idempotency_key'=>'connect-checkout-'.$order->id]);
            $order->update(['stripe_checkout_id'=>$session->id,'checkout_url'=>$session->url,'status'=>'awaiting_payment']);
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
    /** Eigen Stripe-product per klant (custom abonnement), eenmalig via de API aangemaakt. */
    protected function productFor(ConnectOrganization $org): string {
        if ($org->stripe_product_id) return $org->stripe_product_id;
        $product=$this->stripe()->products->create(['name'=>'MilMap Connect — '.$org->name,'description'=>'Connect-seats (accounts en apparaten) voor '.$org->name,
            'metadata'=>['connect_organization_id'=>(string)$org->id,'connect_organization_uuid'=>(string)$org->uuid]],['idempotency_key'=>'connect-product-'.$org->id.'-'.substr(md5($org->name),0,10)]);
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
