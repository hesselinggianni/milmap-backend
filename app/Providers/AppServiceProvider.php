<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/* Models */
use App\Models\User;
use App\Models\Map;

/* Policies */
use App\Policies\UserPolicy;
use App\Policies\MapPolicy;

/* Services */
use App\Services\UserService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserService::class, function ($app) {
            return new UserService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Map::class, MapPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            // Lokaal ruimer: de app-boot doet ~10 calls, waardoor een paar
            // pagina-navigaties binnen een minuut anders al 429 geven.
            return app()->environment('local', 'development')
                ? Limit::perMinute(600)
                : Limit::perMinute(60);
        });
    }
}
