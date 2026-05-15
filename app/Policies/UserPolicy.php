<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user)
    {
        return $user->hasRole('admin') || $user->is_admin || $user->is_superadmin || $user->is_staff;
    }

    public function view(User $user, User $model)
    {
        return $user->hasRole('admin') || $user->id === $model->id;
    }

    public function create(User $user)
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, User $model)
{
    return $user->hasRole('admin') || $user->is_staff || $user->id === $model->id;
}


    public function delete(User $user, User $model)
    {
        return $user->hasRole('admin');
    }
}
