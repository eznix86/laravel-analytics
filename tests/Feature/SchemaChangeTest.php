<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Engines\NativeEngine;
use Eznix86\LaravelAnalytics\Engines\SyncResult;
use Eznix86\LaravelAnalytics\Exceptions\SchemaChanged;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Tests\Fixtures\SchemaFail\Widening;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build the relation narrow, widen or narrow the model, then append again.
 *
 * @param  class-string  $model
 */
function widenAfterFirstBuild(string $fixture, string $model, string $columns): void
{
    usingFixtures($fixture);

    seedOrders([['customer_id' => 1, 'amount' => 100, 'status' => 'paid']]);

    $model::$columns = 'id, customer_id';
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 2, 'amount' => 50, 'status' => 'paid']]);

    $model::$columns = $columns;
}

it('refuses to append when the model gained a column', function () {
    // Arrange
    widenAfterFirstBuild(
        'SchemaFail',
        Widening::class,
        'id, customer_id, amount',
    );

    // Act
    $exitCode = Artisan::call('analytics:sync');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('gained amount')
        ->and($output)->toContain('--full-refresh');
});

it('throws a named exception from the engine so callers can react to it', function () {
    // Arrange
    widenAfterFirstBuild('SchemaFail', Widening::class, 'id, customer_id, amount');

    $node = app(Resolver::class)->resolve()[0];

    // Act
    $build = fn (): SyncResult => app(NativeEngine::class)->sync($node, 'test-run');

    // Assert
    expect($build)->toThrow(SchemaChanged::class, 'gained amount');
});

it('inserts only the shared columns when the change is ignored', function () {
    // Arrange
    widenAfterFirstBuild(
        'SchemaIgnore',
        Eznix86\LaravelAnalytics\Tests\Fixtures\SchemaIgnore\Widening::class,
        'id, customer_id, amount',
    );

    // Act
    $exitCode = Artisan::call('analytics:sync');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Schema::getColumnListing('analytics_widening'))->not->toContain('amount')
        ->and(DB::table('analytics_widening')->count())->toBe(2);
});

it('adds the new column and leaves earlier rows null', function () {
    // Arrange
    widenAfterFirstBuild(
        'SchemaAppend',
        Eznix86\LaravelAnalytics\Tests\Fixtures\SchemaAppend\Widening::class,
        'id, customer_id, amount',
    );

    // Act
    $exitCode = Artisan::call('analytics:sync');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Schema::getColumnListing('analytics_widening'))->toContain('amount')
        ->and(DB::table('analytics_widening')->whereNull('amount')->count())->toBe(1)
        ->and((int) DB::table('analytics_widening')->where('customer_id', 2)->value('amount'))->toBe(50);
});

it('drops a column the model no longer produces when syncing all columns', function () {
    // Arrange
    usingFixtures('SchemaSync');
    $model = Eznix86\LaravelAnalytics\Tests\Fixtures\SchemaSync\Widening::class;

    seedOrders([['customer_id' => 1, 'amount' => 100, 'status' => 'paid']]);

    $model::$columns = 'id, customer_id, amount';
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 2, 'amount' => 50, 'status' => 'paid']]);
    $model::$columns = 'id, customer_id';

    // Act
    $exitCode = Artisan::call('analytics:sync');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Schema::getColumnListing('analytics_widening'))->not->toContain('amount')
        ->and(DB::table('analytics_widening')->count())->toBe(2);
});

it('appends normally when the columns did not change', function () {
    // Arrange
    widenAfterFirstBuild(
        'SchemaFail',
        Widening::class,
        'id, customer_id',
    );

    // Act
    $exitCode = Artisan::call('analytics:sync');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(DB::table('analytics_widening')->count())->toBe(2);
});

it('accepts a widened model after a full refresh', function () {
    // Arrange
    widenAfterFirstBuild(
        'SchemaFail',
        Widening::class,
        'id, customer_id, amount',
    );

    // Act
    $exitCode = Artisan::call('analytics:sync', ['--full-refresh' => true]);

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Schema::getColumnListing('analytics_widening'))->toContain('amount')
        ->and(DB::table('analytics_widening')->count())->toBe(2);
});
