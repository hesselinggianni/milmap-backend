<?php
namespace App\Console\Commands;
use App\Models\ConnectOrganization;
use App\Models\ConnectMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ConnectOrganizationCreate extends Command {
    protected $signature = 'connect:organization {name} {email : Existing owner account} {--seats=50} {--months=12}';
    protected $description = 'Provision an enterprise license and owner for MilMap Connect';
    public function handle(): int {
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($this->argument('email')))])->first();
        if (! $user || $user->isArchived()) { $this->error('Maak eerst een actief MilMap-account voor de eigenaar aan.'); return self::FAILURE; }
        $seats = filter_var($this->option('seats'), FILTER_VALIDATE_INT); $months = filter_var($this->option('months'), FILTER_VALIDATE_INT);
        if (! $seats || $seats < 1 || $seats > 100000 || ! $months || $months < 1 || $months > 120) { $this->error('Ongeldige capaciteit of looptijd.'); return self::FAILURE; }
        $org = DB::transaction(function () use ($user, $seats, $months) {
            $org = ConnectOrganization::create(['name' => $this->argument('name'), 'seat_limit' => $seats, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonths($months)]);
            ConnectMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'first_name' => $user->first_name ?: 'Beheerder', 'last_name' => $user->last_name ?: '',
                'email' => mb_strtolower($user->email), 'role' => 'owner', 'status' => 'active', 'accepted_at' => now(), 'tags' => []]); return $org;
        });
        $this->info('Organisatie '.$org->id.' aangemaakt. De eigenaar kan inloggen op '.config('connect.url').'.'); return self::SUCCESS;
    }
}
