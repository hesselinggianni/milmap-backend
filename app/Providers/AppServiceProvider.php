<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/* Models */
use App\Models\Post;
use App\Models\User;
use App\Models\Map;

/* Policies */
use App\Policies\PostPolicy;
use App\Policies\UserPolicy;
use App\Policies\MapPolicy;

/* Services */
use App\Services\PostService;
use App\Services\UserService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PostService::class, function ($app) {
            return new PostService();
        });

        $this->app->bind(UserService::class, function ($app) {
            return new UserService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Map::class, MapPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60); // Adjust limit as required
        });
    }
}
