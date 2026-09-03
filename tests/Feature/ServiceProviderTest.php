<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Eznix86\LaravelAnalytics\Grammars\SQLiteGrammar;
use Eznix86\LaravelAnalytics\LaravelAnalyticsServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

it('merges the package configuration', function () {
    // Arrange, Act, Assert
    expect(config('analytics.prefix'))->toBe('analytics_')
        ->and(config('analytics.engine'))->toBe('native');
});

it('registers the analytics commands', function () {
    // Arrange, Act
    $commands = array_keys(Artisan::all());

    // Assert
    expect($commands)
        ->toContain('make:analytics')
        ->toContain('analytics:sync')
        ->toContain('analytics:graph')
        ->toContain('analytics:compile');
});

it('publishes its configuration under a dedicated tag', function () {
    // Arrange, Act
    $paths = ServiceProvider::pathsToPublish(
        LaravelAnalyticsServiceProvider::class,
        'laravel-analytics-config',
    );

    // Assert
    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/analytics.php')
        ->and(reset($paths))->toBe(config_path('analytics.php'));
});

it('publishes its migrations under a dedicated tag', function () {
    // Arrange, Act
    $paths = ServiceProvider::pathsToPublish(
        LaravelAnalyticsServiceProvider::class,
        'laravel-analytics-migrations',
    );

    // Assert
    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('database/migrations')
        ->and(reset($paths))->toBe(database_path('migrations'));
});

it('groups every publishable resource under one umbrella tag', function () {
    // Arrange, Act
    $paths = ServiceProvider::pathsToPublish(
        LaravelAnalyticsServiceProvider::class,
        'laravel-analytics',
    );

    // Assert
    expect($paths)->toHaveCount(2);
});

it('resolves the grammar that matches the connection driver', function () {
    // Arrange
    $manager = app(GrammarManager::class);

    // Act
    $grammar = $manager->for('sqlite');

    // Assert
    expect($grammar)->toBeInstanceOf(SQLiteGrammar::class);
});

it('lets an application register a grammar for its own driver', function () {
    // Arrange
    $manager = app(GrammarManager::class);
    $custom = new class extends SQLiteGrammar {};

    // Act
    $manager->extend('clickhouse', $custom::class);

    // Assert
    expect($manager->for('clickhouse'))->toBeInstanceOf(Grammar::class)
        ->and($manager->drivers())->toContain('clickhouse');
});

it('shares one compiler instance across the container', function () {
    // Arrange, Act, Assert
    expect(app(Compiler::class))->toBe(app(Compiler::class));
});
