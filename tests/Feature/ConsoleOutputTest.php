<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('lists models in build order', function () {
    // Arrange, Act
    Artisan::call('analytics:graph');
    $output = Artisan::output();

    // Assert
    expect(strpos($output, 'StgOrder'))->toBeLessThan(strpos($output, 'OrderTotals'))
        ->and(strpos($output, 'OrderTotals'))->toBeLessThan(strpos($output, 'Revenue'));
});

it('shows which relations a model depends on', function () {
    // Arrange, Act
    Artisan::call('analytics:graph');
    $output = Artisan::output();

    // Assert
    expect($output)->toContain('OrderTotals, StgOrder')
        ->and($output)->toMatch('/StgOrder view [ .]+Order\s/');
});

it('prints compiled SQL without touching the database', function () {
    // Arrange, Act
    Artisan::call('analytics:compile', ['model' => 'Revenue']);
    $output = Artisan::output();

    // Assert
    expect($output)->toContain('cte_order_totals as (')
        ->and(fn () => DB::table('analytics_revenue')->count())->toThrow(Exception::class);
});

it('formats the compiled SQL and aligns it under the model name', function () {
    // Arrange, Act
    Artisan::call('analytics:compile', ['model' => 'Revenue']);
    $output = Artisan::output();

    // Assert
    expect($output)->toContain("\n  with\n")
        ->and($output)->toContain("\n    cte_order_totals as (\n")
        ->and($output)->toContain("\n      select\n        customer_id,\n");
});

it('reports an unknown model with the names it does know', function () {
    // Arrange, Act
    $exitCode = Artisan::call('analytics:compile', ['model' => 'Nope']);
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Revenue');
});

it('warns instead of failing when no analytics models exist', function () {
    // Arrange
    config()->set('analytics.path', __DIR__.'/../Fixtures/Empty');

    // Act
    $exitCode = Artisan::call('analytics:sync');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No analytics models found');
});
