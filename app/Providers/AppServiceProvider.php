<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One plan-access picture per request (it is read many times while
        // building a shop page or pricing an order).
        $this->app->scoped(\App\Services\PlanAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Baseline API rate limit (Milestone 16 §Rate Limiting): 120 req/min per
        // authenticated user, else per IP. Disabled under tests so the suite,
        // which shares one IP, isn't throttled.
        RateLimiter::for('api', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // The plan-access picture belongs to one request; a long-lived worker
        // (or the test client) must not carry it into the next.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            fn () => $this->app->forgetScopedInstances(),
        );
    }
}
