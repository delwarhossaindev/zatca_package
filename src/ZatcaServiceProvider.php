<?php

namespace delwarhossaindev\Zatca;

use Illuminate\Support\ServiceProvider;
use delwarhossaindev\Zatca\Console\OnboardCommand;
use delwarhossaindev\Zatca\Console\PayloadCommand;
use delwarhossaindev\Zatca\Console\StatusCommand;
use delwarhossaindev\Zatca\Console\SubmitPendingCommand;

class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zatca.php', 'zatca');

        $this->app->singleton(ZatcaManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/zatca.php' => config_path('zatca.php'),
            ], 'zatca-config');

            $this->commands([
                OnboardCommand::class,
                SubmitPendingCommand::class,
                StatusCommand::class,
                PayloadCommand::class,
            ]);
        }
    }
}
