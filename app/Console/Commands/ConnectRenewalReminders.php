<?php
namespace App\Console\Commands;

use App\Mail\ConnectRenewalReminder;
use App\Models\{AppNotification, ConnectOrganization};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ConnectRenewalReminders extends Command
{
    protected $signature = 'connect:renewal-reminders {--test-to= : Stuur alleen een voorbeeldmail naar dit adres}';
    protected $description = 'Meldt Connect-beheerders (melding + e-mail) een maand voordat de licentie verloopt';

    public function handle(): int
    {
        $url = rtrim(config('connect.url'), '/').'/licenties';
        if ($to = $this->option('test-to')) {
            Mail::to($to)->send(new ConnectRenewalReminder('Voorbeeld Organisatie', now()->addDays(30), 30, 50, $url));
            $this->info("Voorbeeldmail verstuurd naar {$to}.");
            return self::SUCCESS;
        }
        $sent = 0;
        ConnectOrganization::where('status', 'active')->with('licenses')->get()->each(function ($org) use ($url, &$sent) {
            $info = $org->renewalInfo();
            if (!$info || !$info['due'] || $info['days_left'] < 0) return;
            $end = $info['ends_at'];
            if ($org->renewal_reminded_for && \Illuminate\Support\Carbon::parse($org->renewal_reminded_for)->equalTo($end)) return;
            $managers = $org->members()->where('status', 'active')->whereIn('role', ['owner', 'admin'])->with('user:id,email')->get();
            $emails = $managers->map(fn ($m) => $m->user?->email ?: $m->email)->push($org->billing_email, $org->contact_email)->filter()->unique(fn ($e) => strtolower($e));
            foreach ($emails as $email) {
                try { Mail::to($email)->send(new ConnectRenewalReminder($org->name, $end, max(0, $info['days_left']), $org->seat_limit, $url)); } catch (\Throwable $e) { report($e); }
            }
            foreach ($managers->pluck('user_id')->filter()->unique() as $uid) {
                AppNotification::notifyUser((int) $uid, 'connect.renewal', 'Je licentie verloopt binnenkort', "De MilMap Connect-licentie van {$org->name} loopt af op ".$end->format('d-m-Y').'.', ['action_url' => $url, 'organization_id' => $org->id]);
            }
            $org->forceFill(['renewal_reminded_for' => $end])->save();
            $sent++;
        });
        $this->info("Herinneringen verstuurd voor {$sent} organisatie(s).");
        return self::SUCCESS;
    }
}
