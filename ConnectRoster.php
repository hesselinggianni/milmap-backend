<?php
namespace App\Services;
use App\Models\ConnectMember;
use App\Models\ConnectOrganization;
use App\Models\User;
use App\Mail\ConnectInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class ConnectRoster {
    public function invite(ConnectOrganization $organization, array $rows): array {
        $deliveries = DB::transaction(function () use ($organization, $rows) {
            $org = ConnectOrganization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if (! $org->licensed()) throw ValidationException::withMessages(['license' => 'De enterprise-licentie is niet actief.']);
            $seen = []; $new = [];
            foreach ($rows as $i => $row) {
                $email = mb_strtolower(trim($row['email']));
                if (isset($seen[$email])) throw ValidationException::withMessages(["members.$i.email" => 'Dubbel e-mailadres in deze import.']);
                $seen[$email] = true;
                $member = $org->members()->where('email', $email)->first();
                if ($member && ($member->status === 'active' || ($member->status === 'pending' && $member->expires_at > now())))
                    throw ValidationException::withMessages(["members.$i.email" => 'Deze gebruiker is al actief of uitgenodigd.']);
                $new[] = [$row, $email, $member];
            }
            if ($org->occupiedSeats() + count($new) > $org->seat_limit)
                throw ValidationException::withMessages(['license' => 'Er zijn onvoldoende licentieplaatsen beschikbaar.']);
            $deliveries = [];
            foreach ($new as [$row, $email, $member]) {
                $token = Str::random(64);
                $data = ['organization_id' => $org->id, 'email' => $email, 'first_name' => trim($row['first_name']), 'last_name' => trim($row['last_name']),
                    'tags' => array_values(array_unique($row['tags'] ?? [])), 'role' => $row['role'] ?? 'member', 'status' => 'pending',
                    'user_id' => null, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'accepted_at' => null, 'mail_sent_at' => null];
                if ($member) $member->update($data); else $member = ConnectMember::create($data);
                $deliveries[] = [$member, $token];
            }
            return $deliveries;
        });
        $failed = [];
        foreach ($deliveries as [$member, $token]) {
            try {
                Mail::to($member->email)->send(new ConnectInvitation($organization->name, $member->first_name,
                    rtrim(config('connect.url'), '/').'/uitnodiging#token='.$token));
                $member->update(['mail_sent_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('[connect] invitation delivery failed', ['member_id' => $member->id]);
                $failed[] = $member->id;
            }
        }
        return ['invited' => count($deliveries), 'mail_failed_ids' => $failed];
    }
    public function accept(User $user, string $token): ConnectMember {
        $candidate = ConnectMember::where('token_hash', hash('sha256', $token))->firstOrFail();
        return DB::transaction(function () use ($candidate, $user, $token) {
            $org = ConnectOrganization::whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
            $member = ConnectMember::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals((string) $member->token_hash, hash('sha256', $token)), 410);
            abort_unless(mb_strtolower($user->email) === $member->email, 403, 'Log in met het uitgenodigde e-mailadres.');
            abort_unless($member->status === 'pending' && $member->expires_at > now(), 410, 'De uitnodiging is verlopen of ingetrokken.');
            abort_unless($org->licensed(), 422, 'De enterprise-licentie is niet actief.');
            $member->update(['user_id' => $user->id, 'status' => 'active', 'accepted_at' => now(), 'token_hash' => null]);
            return $member;
        });
    }
}
