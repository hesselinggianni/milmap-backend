<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class StripeBillingProfile
{
    private function stripe(): StripeClient
    {
        return new StripeClient(['api_key' => config('billing.stripe_secret') ?: null]);
    }

    public function read(User $user): array
    {
        $details = $user->billing_details ?: [
            'name' => $user->full_name, 'email' => $user->email, 'phone' => '',
            'address' => array_fill_keys(['line1', 'line2', 'postal_code', 'city', 'state', 'country'], ''),
        ];
        if ($user->stripe_id) {
            $customer = $this->stripe()->customers->retrieve($user->stripe_id);
            if ($customer->deleted ?? false) throw new \RuntimeException('De gekoppelde Stripe-klant is verwijderd.');
            $details = $this->details($customer);
        }
        return ['details' => $details, 'stripe_customer_id' => $user->stripe_id, 'synced_at' => $user->billing_synced_at?->toIso8601String()];
    }

    public function update(User $user, array $details, string $requestId): array
    {
        return DB::transaction(function () use ($user, $details, $requestId) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = 'admin-billing-'.$locked->id.'-'.$requestId.'-'.substr(hash('sha256', json_encode($details)), 0, 20);
            $stripe = $this->stripe();
            if ($locked->stripe_id) {
                $customer = $stripe->customers->update($locked->stripe_id, $details, ['idempotency_key' => $key]);
            } else {
                $customer = $stripe->customers->create($details + ['metadata' => ['user_id' => (string) $locked->id]], ['idempotency_key' => $key]);
            }
            // Persist only after Stripe acknowledges the same profile. Account login data stays separate.
            $stored = $this->details($customer);
            $locked->forceFill(['stripe_id' => $customer->id, 'billing_details' => $stored, 'billing_synced_at' => now()])->save();
            return ['details' => $stored, 'stripe_customer_id' => $customer->id, 'synced_at' => $locked->billing_synced_at->toIso8601String()];
        });
    }

    private function details($customer): array
    {
        return [
            'name' => $customer->name ?? '', 'email' => $customer->email ?? '', 'phone' => $customer->phone ?? '',
            'address' => array_combine(['line1', 'line2', 'postal_code', 'city', 'state', 'country'],
                array_map(fn ($key) => $customer->address?->{$key} ?? '', ['line1', 'line2', 'postal_code', 'city', 'state', 'country'])),
        ];
    }
}
