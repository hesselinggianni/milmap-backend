<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function getAllUsers()
    {
        return User::all();
    }

    public function createUser(array $data)
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

        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'settings' => $defaultSettings,
        ]);
    }

    public function getUserById(int $id)
    {
        return User::findOrFail($id);
    }

    public function updateUser(int $id, array $data)
    {
        $user = User::findOrFail($id);


        if (isset($data['first_name'])) {
            $user->first_name = $data['first_name'];
        }
        if (isset($data['last_name'])) {
            $user->last_name = $data['last_name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }
        if (isset($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if (isset($data['language'])) {
            $user->language = $data['language'];
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
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
            $user->settings = array_merge($defaultSettings, $user->settings ?? [], $data['settings']);
        }

        $user->save();

        return $user;
    }

    public function deleteUser(int $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return true;
    }
}
