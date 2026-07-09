<?php

namespace App\Providers;

use App\Services\AI\FlowSessionContext;
use App\Services\AI\UsageCollector;
use App\Services\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (not singleton) so the usage buffer resets between queue jobs.
        $this->app->scoped(UsageCollector::class);

        // Scoped for the same reason: the flow session key is per generation run.
        $this->app->scoped(FlowSessionContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Admin-edited runtime settings shadow the env-backed cubfable config.
        $this->app->make(AppSettings::class)->apply();
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
