<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Batched;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Inlined;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Keyed;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Rollup;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Staged;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Stream;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Versioned;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Microbatch\DailyOrders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    usingFixtures('Fluent');

    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
});

it('takes the materialization from the return type of computes, with nothing overridden', function (string $fixture, Materialization $expected) {
    // Arrange
    $model = new $fixture;

    // Act
    $materialization = $model->materialization();

    // Assert
    expect($materialization)->toBe($expected);
})->with([
    'Query' => [Rollup::class, Materialization::Table],
    'ViewQuery' => [Staged::class, Materialization::View],
    'EphemeralQuery' => [Inlined::class, Materialization::Ephemeral],
    'IncrementalQuery' => [Stream::class, Materialization::Incremental],
    'MicrobatchQuery' => [Batched::class, Materialization::Microbatch],
    'SnapshotQuery' => [Versioned::class, Materialization::Snapshot],
]);

it('reads the replace key off the query instead of an overridden method', function () {
    // Arrange
    $keyed = new Keyed;
    $appending = new Stream;

    // Act, Assert
    expect($keyed->uniqueKey())->toBe(['month'])
        ->and($appending->uniqueKey())->toBe([]);
});

it('reads the batch configuration off the query instead of four overridden methods', function () {
    // Arrange
    $model = new Batched;

    // Act, Assert
    expect($model->eventTime())->toBe('placed_on')
        ->and($model->batchSize())->toBe(BatchSize::Month)
        ->and($model->begin())->toBe('2026-01-01');
});

it('reads the tracked and watched columns off a snapshot query', function () {
    // Arrange
    $model = new Versioned;

    // Act, Assert
    expect($model->uniqueKey())->toBe(['id'])
        ->and($model->checkColumns())->toBe(['amount']);
});

it('never calls computes on a model that returns raw SQL, which may need a build to be callable', function () {
    // Arrange
    $model = new DailyOrders;

    // Act
    $eventTime = $model->eventTime();

    // Assert
    expect($eventTime)->toBe('placed_on');
});

it('builds every materialization the return types declare', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $versions = DB::table('analytics_versioned')->count();

    // Assert
    expect($versions)->toBe(2)
        ->and(DB::table('analytics_staged')->count())->toBe(2)
        ->and(Schema::hasTable('analytics_inlined'))->toBeFalse();
});
