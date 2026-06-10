<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the generic user CRUD endpoints.
 *
 * `is_admin` (boolean column on the users table) is the single source of truth
 * for admin rights everywhere in the app (AdminAuth middleware, AdminController,
 * AdminAuthController, MakeAdminCommand). The previous version of this policy
 * referenced Spatie's hasRole() plus the non-existent `is_staff`/`is_superadmin`
 * attributes; hasRole() threw at runtime, which is why UserController's
 * authorize() calls had been commented out — leaving an account-takeover IDOR
 * (any logged-in user could rewrite another account's email/password by id).
 *
 * We standardize on `is_admin` + "self": an admin may manage any account, and a
 * user may view/update/delete only their own record.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, User $model): bool
    {
        return (bool) $user->is_admin || $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, User $model): bool
    {
        return (bool) $user->is_admin || $user->id === $model->id;
    }

    public function delete(User $user, User $model): bool
    {
        return (bool) $user->is_admin || $user->id === $model->id;
    }
}
