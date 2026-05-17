<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {email : The email of the user to make admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a user an admin';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');

        // Find user by email
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Gebruiker met email '{$email}' niet gevonden.");
            return 1;
        }

        // Check if already admin
        if ($user->is_admin) {
            $this->warn("Gebruiker '{$email}' is al admin.");
            return 0;
        }

        // Set admin status
        $user->is_admin = true;
        $user->save();

        $this->info("Gebruiker '{$email}' is nu admin.");
        return 0;
    }
}
