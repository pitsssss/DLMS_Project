<?php

namespace App\Providers;

use App\Modules\AIAgent\Services\AgentLocaleContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register AgentLocaleContext as scoped service to prevent locale leakage
        // between users in long-running processes (Octane, Swoole, Workers).
        $this->app->scoped(AgentLocaleContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app()->setLocale(config('app.locale', 'ar'));
    }
}
