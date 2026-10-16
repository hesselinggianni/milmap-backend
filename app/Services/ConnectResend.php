<?php
namespace App\Services;
use App\Models\ConnectOrganization;
use App\Models\ConnectMember;
use App\Mail\ConnectInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
class ConnectResend {
    public function send(ConnectOrganization $org, ConnectMember $member): array {
        $token = DB::transaction(function () use ($org, $member) {
            $locked = ConnectOrganization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $member->refresh(); abort_unless($member->status === 'pending', 422);
            abort_unless($locked->licensed(), 422, 'De licentie is niet actief.');
            abort_if($member->expires_at <= now() && $locked->usedLicenses() >= $locked->seat_limit, 422, 'Er is geen licentieplaats beschikbaar.');
            $token = Str::random(64); $member->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14), 'mail_sent_at' => null]); return $token;
        });
        try {
            Mail::to($member->email)->send(new ConnectInvitation($org->name, $member->first_name, rtrim(config('connect.url'), '/').'/uitnodiging#token='.$token));
            $member->update(['mail_sent_at' => now()]); return ['sent' => true];
        } catch (\Throwable $e) { return ['sent' => false, 'message' => 'Verzending mislukt. Probeer opnieuw.']; }
    }
}
