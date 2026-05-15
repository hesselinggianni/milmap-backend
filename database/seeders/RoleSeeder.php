<?php

// database/seeders/RoleSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Maak een admin-rol aan
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Wijs een rol toe aan een gebruiker
        $user = User::find(1); // Vervang met de juiste user ID
        if ($user) {
            $user->assignRole($adminRole);
        }
    }
}
