<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests;

use Eznix86\LaravelAnalytics\LaravelAnalyticsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAnalyticsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.warehouse', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('analytics.path', __DIR__.'/Fixtures/Graph');
        $app['config']->set('analytics.namespace', 'Eznix86\\LaravelAnalytics\\Tests\\Fixtures\\Graph');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->integer('amount');
            $table->string('status');
            $table->timestamp('placed_on')->nullable();
        });
    }
}
