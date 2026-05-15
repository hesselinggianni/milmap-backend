<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;

class PostPolicy
{
    /**
     * Determine if the user can view any posts.
     */
    public function viewAny(User $user)
    {
        return $user->hasPermission('view-any-post'); // Example permission check
    }

    /**
     * Determine if the user can view a specific post.
     */
    public function view(User $user, Post $post)
    {
        return $user->id === $post->user_id || $user->hasPermission('view-post');
    }

    /**
     * Determine if the user can create posts.
     */
    public function create(User $user)
    {
        return $user->hasRole('author') || $user->hasPermission('create-post');
    }

    /**
     * Determine if the user can update the post.
     */
    public function update(User $user, Post $post)
    {
        return $user->id === $post->user_id || $user->hasPermission('update-post');
    }

    /**
     * Determine if the user can delete the post.
     */
    public function delete(User $user, Post $post)
    {
        return $user->id === $post->user_id && $user->hasPermission('delete-post');
    }

    /**
     * Determine if the user can restore a deleted post.
     */
    public function restore(User $user, Post $post)
    {
        return $user->hasRole('admin') || $user->hasPermission('restore-post');
    }

    /**
     * Determine if the user can permanently delete the post.
     */
    public function forceDelete(User $user, Post $post)
    {
        return $user->hasRole('admin') && $user->hasPermission('force-delete-post');
    }
}
