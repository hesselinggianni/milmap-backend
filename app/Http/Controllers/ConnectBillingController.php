<?php
namespace App\Http\Controllers;
use App\Models\{ConnectOrganization, ConnectOrder};
use App\Services\ConnectBilling;
use Illuminate\Http\Request;
class ConnectBillingController extends Controller {
    private function manager(Request $r, ConnectOrganization $org): void {
        abort_unless($org->members()->where('user_id',$r->user()->id)->where('status','active')->whereIn('role',['owner','admin'])->exists(),403);
    }
    public function catalog(Request $r, ConnectOrganization $organization, ConnectBilling $billing) {
        $this->manager($r,$organization); $quote=$billing->quote($organization); unset($quote['product'],$quote['price_id'],$quote['tax_behavior']);
        return response()->json(['organization'=>['id'=>$organization->id,'name'=>$organization->name],'pricing'=>$quote]);
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
        $data=$r->validate(['request_id'=>'required|uuid','seats'=>'required|integer|min:1|max:100000','term_months'=>'required|integer|in:1,12','auto_renew'=>'sometimes|boolean','payment_method'=>'required|in:direct,invoice','accepted'=>'accepted','expected_unit_monthly_cents'=>'required|integer|min:1','expected_currency'=>'required|string|size:3',
            'billing_details'=>'required|array:company_name,email,address','billing_details.company_name'=>'required|string|max:190','billing_details.email'=>'required|email|max:190',
            'billing_details.address'=>'required|array:line1,line2,postal_code,city,country','billing_details.address.line1'=>'required|string|max:190','billing_details.address.line2'=>'nullable|string|max:190',
            'billing_details.address.postal_code'=>'required|string|max:30','billing_details.address.city'=>'required|string|max:100','billing_details.address.country'=>'required|string|size:2|regex:/^[A-Z]{2}$/']);
        $order=$billing->order($organization,$r->user()->id,$data);
        return response()->json(['order'=>$order,'checkout_url'=>$order->checkout_url],201);
    }
}
