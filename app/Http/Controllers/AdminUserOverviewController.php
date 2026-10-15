<?php

namespace App\Http\Controllers;

use App\Models\{User, Map, RouteMap, Mission, TeamMember};
use App\Services\MapAccess;
use Illuminate\Http\Request;
use Stripe\StripeClient;

/** Read-only customer overview, protected by the admin route group. */
class AdminUserOverviewController extends Controller
{
    public function workspace(Request $request, $userId)
    {
        $user = User::findOrFail($userId);
        $data = $request->validate(['section' => 'required|in:maps,routes,missions,memberships', 'page' => 'sometimes|integer|min:1']);
        $query = match ($data['section']) {
            'maps' => app(MapAccess::class)->accessibleQuery($user)
                ->select('maps.id', 'title', 'status', 'owner_id', 'updated_at'),
            'routes' => RouteMap::where('owner_id', $user->id)
                ->select('id', 'title', 'map_id', 'updated_at'),
            'missions' => Mission::where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)->orWhereHas('collaborators', fn ($c) => $c->where('user_id', $user->id)->where('status', 'accepted')->whereIn('role', ['viewer', 'editor', 'admin']));
            })->select('id', 'name', 'status', 'owner_id', 'updated_at'),
            'memberships' => TeamMember::where('user_id', $user->id)->with('team:id,name')->select('id', 'team_id', 'role', 'status', 'updated_at'),
        };
        return response()->json($query->orderByDesc('updated_at')->orderBy('id')->paginate(25));
    }

    public function invoices(Request $request, $userId)
    {
        $user = User::findOrFail($userId);
        $data = $request->validate(['after' => 'sometimes|nullable|string|max:255']);
        if (!$user->stripe_id) return response()->json(['invoices' => [], 'customer' => null, 'has_more' => false]);
        try {
            $stripe = new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
            $customer = $stripe->customers->retrieve($user->stripe_id);
            $params = ['customer' => $user->stripe_id, 'limit' => 25];
            if (!empty($data['after'])) $params['starting_after'] = $data['after'];
            $list = $stripe->invoices->all($params);
            $invoices = array_map(fn ($inv) => [
                'id' => $inv->id, 'number' => $inv->number, 'status' => $inv->status,
                'created' => $inv->created ? date(DATE_ATOM, $inv->created) : null,
                'total' => $inv->total, 'paid' => $inv->amount_paid, 'due' => $inv->amount_remaining,
                'currency' => strtoupper($inv->currency),
                'url' => $inv->hosted_invoice_url, 'pdf' => $inv->invoice_pdf,
            ], $list->data);
            return response()->json([
                'invoices' => $invoices, 'has_more' => $list->has_more,
                'customer' => [
                    'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone,
                    'address' => $customer->address?->toArray(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Facturatiegegevens konden niet worden opgehaald. Probeer het opnieuw.'], 502);
        }
    }
}
