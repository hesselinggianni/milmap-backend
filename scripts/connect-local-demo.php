<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== '/tmp/milmap-connect-local.sqlite') throw new RuntimeException('Only the isolated Connect local database may be seeded.');
$owner = App\Models\User::firstOrCreate(['email' => 'beheer@connect.test'], ['first_name'=>'Demo', 'last_name'=>'Beheerder', 'password'=>Illuminate\Support\Facades\Hash::make('ConnectTest2026!'), 'email_verified_at'=>now(), 'trial_ends_at'=>now()->subDay()]);
$student = App\Models\User::firstOrCreate(['email' => 'leerling@connect.test'], ['first_name'=>'Sam', 'last_name'=>'Jansen', 'password'=>Illuminate\Support\Facades\Hash::make('ConnectTest2026!'), 'email_verified_at'=>now(), 'trial_ends_at'=>now()->subDay()]);
$org = App\Models\ConnectOrganization::firstOrCreate(['name'=>'MilMap Demo Onderwijs'], ['seat_limit'=>50, 'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addYear()]);
foreach ([[$owner,'owner',[]],[$student,'member',['Klas 1']]] as [$user,$role,$tags]) App\Models\ConnectMember::firstOrCreate(['organization_id'=>$org->id,'email'=>$user->email],['user_id'=>$user->id,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'role'=>$role,'status'=>'active','tags'=>$tags,'accepted_at'=>now()]);
echo "Local demo ready: beheer@connect.test / ConnectTest2026!\n";
