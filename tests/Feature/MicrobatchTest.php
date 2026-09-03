<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Engines\NativeEngine;
use Eznix86\LaravelAnalytics\Engines\SyncResult;
use Eznix86\LaravelAnalytics\Exceptions\MissingBatchBegin;
use Eznix86\LaravelAnalytics\Exceptions\OutsideBatch;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Microbatch\DailyOrders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    usingFixtures('Microbatch');

    Carbon::setTestNow('2026-01-05 12:00:00');
    DailyOrders::$begin = '2026-01-01';

    seedOrders([
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid', 'placed_on' => '2026-01-01 09:00:00'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid', 'placed_on' => '2026-01-01 18:00:00'],
        ['customer_id' => 3, 'amount' => 70, 'status' => 'paid', 'placed_on' => '2026-01-03 10:00:00'],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function daily(): Collection
{
    return DB::table('analytics_daily_orders')->orderBy('placed_on')->get();
}

function batchOn(string $day): ?object
{
    return daily()->first(static fn (object $row): bool => str_starts_with((string) $row->placed_on, $day));
}

it('builds every batch from begin up to now on the first run', function () {
    // Arrange, Act
    Artisan::call('analytics:sync');

    // Assert
    expect(daily())->toHaveCount(2)
        ->and(daily()->pluck('orders')->map(intval(...))->all())->toBe([2, 1]);
});

it('slices rows into the batch their event time belongs to', function () {
    // Arrange, Act
    Artisan::call('analytics:sync');

    // Assert
    $first = batchOn('2026-01-01');

    expect((int) $first->orders)->toBe(2)
        ->and((int) $first->revenue)->toBe(150);
});

it('rebuilds only the lookback window on the next run', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([
        ['customer_id' => 4, 'amount' => 400, 'status' => 'paid', 'placed_on' => '2026-01-01 11:00:00'],
        ['customer_id' => 5, 'amount' => 500, 'status' => 'paid', 'placed_on' => '2026-01-05 11:00:00'],
    ]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    $first = batchOn('2026-01-01');
    $last = batchOn('2026-01-05');

    expect((int) $first->orders)->toBe(2)
        ->and((int) $last->orders)->toBe(1);
});

it('replaces a batch rather than doubling it when run again', function () {
    // Arrange
    Artisan::call('analytics:sync');
    $before = daily()->count();

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(daily())->toHaveCount($before)
        ->and(daily()->pluck('placed_on')->duplicates())->toBeEmpty();
});

it('picks up a late arriving row inside the lookback window', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([
        ['customer_id' => 6, 'amount' => 900, 'status' => 'paid', 'placed_on' => '2026-01-04 23:00:00'],
    ]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    $fourth = batchOn('2026-01-04');

    expect($fourth)->not->toBeNull()
        ->and((int) $fourth->revenue)->toBe(900);
});

it('rebuilds an explicit range on request', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([
        ['customer_id' => 7, 'amount' => 33, 'status' => 'paid', 'placed_on' => '2026-01-01 07:00:00'],
    ]);

    // Act
    Artisan::call('analytics:sync', [
        '--event-time-start' => '2026-01-01',
        '--event-time-end' => '2026-01-01',
    ]);

    // Assert
    $first = batchOn('2026-01-01');

    expect((int) $first->orders)->toBe(3);
});

it('refuses a microbatch model with nothing to start from', function () {
    // Arrange
    usingFixtures('MicrobatchNoBegin');
    $node = app(Resolver::class)->resolve()[0];

    // Act
    $build = fn (): SyncResult => app(NativeEngine::class)->sync($node, 'test-run');

    // Assert
    expect($build)->toThrow(MissingBatchBegin::class, 'begin()');
});

it('refuses to build the batch predicate outside a batch', function () {
    // Arrange
    $model = new DailyOrders;

    // Act
    $compile = fn (): Compiled => app(Compiler::class)->compile($model);

    // Assert
    expect($compile)->toThrow(OutsideBatch::class);
});
