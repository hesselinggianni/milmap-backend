<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class InitializeUserSettings extends Command
{
    protected $signature = 'users:init-settings';
    protected $description = 'Initialize default settings for all users';

    public function handle()
    {
        $defaultSettings = [
            'locationformat' => 'mgrs',
            'mgrs_precision' => 8,
            'maxZoom' => 100,
            'mapExtent' => null,
            'language' => 'nl',
            'useCurrentCoordsAsBounds' => false,
            'locationEnabled' => false,
            'terrain3D' => false,
            'terrain3DPitch' => 60,
            'showMgrsGrid' => false,
            'showScaleLine' => true,
        ];

        $users = User::all();
        $count = 0;

        foreach ($users as $user) {
            if (!$user->settings) {
                $user->settings = $defaultSettings;
            } else {
                // Merge with defaults to add any missing settings
                $user->settings = array_merge($defaultSettings, $user->settings);
            }
            $user->save();
            $count++;
        }

        $this->info("Initialized settings for {$count} users");
    }
}
