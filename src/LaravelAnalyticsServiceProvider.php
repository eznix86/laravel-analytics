<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Console\Commands\CompileCommand;
use Eznix86\LaravelAnalytics\Console\Commands\GraphCommand;
use Eznix86\LaravelAnalytics\Console\Commands\MakeAnalyticsCommand;
use Eznix86\LaravelAnalytics\Console\Commands\PruneCommand;
use Eznix86\LaravelAnalytics\Console\Commands\SyncCommand;
use Eznix86\LaravelAnalytics\Console\Commands\TestCommand;
use Eznix86\LaravelAnalytics\Engines\NativeEngine;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Testing\Runner;
use Illuminate\Support\ServiceProvider;

class LaravelAnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/analytics.php', 'analytics');

        $this->app->singleton(GrammarManager::class);
        $this->app->singleton(Compiler::class);
        $this->app->singleton(Resolver::class);
        $this->app->singleton(NativeEngine::class);
        $this->app->singleton(Runner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/analytics.php' => config_path('analytics.php'),
        ], ['laravel-analytics', 'laravel-analytics-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-analytics', 'laravel-analytics-migrations']);

        $this->commands([
            MakeAnalyticsCommand::class,
            GraphCommand::class,
            CompileCommand::class,
            PruneCommand::class,
            SyncCommand::class,
            TestCommand::class,
        ]);
    }
}
