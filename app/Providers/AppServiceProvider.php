<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth is not installed, but the Guard contract is still aliased to
        // 'auth.driver'. Bind an always-guest guard so the database session
        // handler can resolve it and store a null user_id.
        $this->app->singleton(
            'auth.driver',
            fn ($app) => new RequestGuard(fn () => null, $app['request']),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

    }
}
