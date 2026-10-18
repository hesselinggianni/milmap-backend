<?php
namespace App\Http\Controllers;
use App\Models\{ConnectOrganization, ConnectOrder};
use App\Services\ConnectBilling;
use Illuminate\Http\Request;
class ConnectBillingController extends Controller {
    private function manager(Request $r, ConnectOrganization $org): void {
        abort_unless($org->members()->where('user_id',$r->user()->id)->where('status','active')->whereIn('role',['owner','admin'])->exists(),403);
    }
    /** Validatie van factuurgegevens; dezelfde regels voor de checkout en het Connect-account. */
    private function detailRules(string $p = ''): array {
        return [$p.'company_name'=>'required|string|max:190', $p.'email'=>'required|email|max:190',
            $p.'address'=>'required|array:line1,line2,postal_code,city,country', $p.'address.line1'=>'required|string|max:190', $p.'address.line2'=>'nullable|string|max:190',
            $p.'address.postal_code'=>'required|string|max:30', $p.'address.city'=>'required|string|max:100', $p.'address.country'=>'required|string|size:2|regex:/^[A-Z]{2}$/'];
    }
    public function catalog(Request $r, ConnectOrganization $organization, ConnectBilling $billing) {
        $this->manager($r,$organization);
        // Tijdens de proefperiode is bestellen gratis: de prijs voor na de proefperiode is mooi meegenomen, maar geen vereiste.
        $trial=$organization->trialInfo();
        try { $quote=$billing->quote($organization); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if (!$trial) throw $e;
            return response()->json(['organization'=>['id'=>$organization->id,'name'=>$organization->name],'pricing'=>null,'trial'=>$trial,'billing_details'=>$organization->billingProfile($r->user()->email),'pending_addition'=>$this->pendingFor($r,$organization)]);
        }
        unset($quote['product'],$quote['price_id'],$quote['tax_behavior'],$quote['tax_rate_id']);
        // Vanuit "toevoegen" in Connect: de checkout toont wat er na betaling wordt toegevoegd en het minimum aantal seats.
        $pending=$this->pendingFor($r,$organization,false);
        return response()->json(['organization'=>['id'=>$organization->id,'name'=>$organization->name],'pricing'=>$quote+['coterm'=>collect([1,6,12])->mapWithKeys(fn ($m)=>[$m=>($c=$billing->coterm($organization,$m,$quote['unit_monthly_cents'])) ? ['ends_at'=>$c['ends_at']->toIso8601String(),'remaining_days'=>$c['remaining_days'],'term_days'=>$c['term_days'],'unit_term_cents'=>$c['unit_term_cents']] : null])->all()],'billing_details'=>$organization->billingProfile($r->user()->email),
            'pending_addition'=>$pending ? app(\App\Services\ConnectPendingAdditions::class)->present($pending) : null,'trial'=>$trial]);
    }
    /** @return array|\App\Models\ConnectPendingAddition|null presentatie (of model met $present=false) van de aanvraag uit ?pending= */
    private function pendingFor(Request $r, ConnectOrganization $organization, bool $present = true) {
        $pending=$r->filled('pending') && \Illuminate\Support\Str::isUuid((string)$r->query('pending')) ? \App\Models\ConnectPendingAddition::where('organization_id',$organization->id)->where('status','pending')->find($r->query('pending')) : null;
        return $present ? ($pending ? app(\App\Services\ConnectPendingAdditions::class)->present($pending) : null) : $pending;
    }
    /** Factuurgegevens wijzigen vanuit het Connect-account (alleen eigenaar/beheerder). */
    public function updateDetails(Request $r, ConnectOrganization $organization, ConnectBilling $billing) {
        $this->manager($r,$organization);
        $data=$r->validate($this->detailRules());
        $billing->updateDetails($organization,$data);
        app(\App\Services\ConnectManagement::class)->audit($organization->id,'billing_details.updated','ConnectOrganization',$organization->id);
        return response()->json(['billing_details'=>$organization->billingProfile($r->user()->email)]);
    }
    public function index(Request $r, ConnectOrganization $organization) {
        $this->manager($r,$organization);
        return response()->json(ConnectOrder::where('organization_id',$organization->id)->with('invoices')->latest()->paginate(30));
    }
    public function cancel(Request $r, ConnectOrganization $organization, ConnectOrder $order, ConnectBilling $billing) {
        $this->manager($r,$organization); abort_unless((int)$order->organization_id===(int)$organization->id,404);
        $billing->cancel($order); return response()->json(['order'=>$order]);
    }
    public function store(Request $r, ConnectOrganization $organization, ConnectBilling $billing) {
        $this->manager($r,$organization); abort_unless($organization->status==='active',403,'Deze organisatie is niet actief.');
        // Proefperiode: geen betaling, de licenties staan direct op de proeflicentie.
        if ($organization->onTrial() || ConnectOrder::where('organization_id',$organization->id)->where('request_id',(string)$r->input('request_id'))->where('payment_method','trial')->exists()) {
            $trial=$r->validate(['request_id'=>'required|uuid','seats'=>'required|integer|min:1|max:100000','accepted'=>'accepted','pending_addition_id'=>'sometimes|nullable|uuid']);
            return response()->json(['order'=>app(\App\Services\ConnectTrial::class)->order($organization,$r->user()->id,$trial),'trial'=>true],201);
        }
        $data=$r->validate(['request_id'=>'required|uuid','seats'=>'required|integer|min:1|max:100000','term_months'=>'required|integer|in:1,6,12','auto_renew'=>'sometimes|boolean','payment_method'=>'required|in:direct,invoice','embedded'=>'sometimes|boolean','accepted'=>'accepted','expected_unit_monthly_cents'=>'required|integer|min:1','expected_total_incl_cents'=>'required|integer|min:1','expected_currency'=>'required|string|size:3','pending_addition_id'=>'sometimes|nullable|uuid',
            'billing_details'=>'required|array:company_name,email,address']+$this->detailRules('billing_details.'));
        $order=$billing->order($organization,$r->user()->id,$data);
        $secret=$billing->embeddedSecret($order);
        return response()->json(['order'=>$order,'checkout_url'=>$order->checkout_url]+($secret ? ['client_secret'=>$secret,'publishable_key'=>$billing->publishableKey()] : []),201);
    }
}
