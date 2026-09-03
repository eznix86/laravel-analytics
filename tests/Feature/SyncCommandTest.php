<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\OrderTotals;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Revenue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
        ['customer_id' => 3, 'amount' => 900, 'status' => 'cancelled'],
    ]);
});

it('builds the whole graph so the analytics model returns aggregated rows', function () {
    // Arrange, Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(Revenue::query()->where('customer_id', 1)->value('total'))->toEqual(300)
        ->and(Revenue::query()->where('customer_id', 2)->value('total'))->toEqual(50);
});

it('applies the staging filter so cancelled orders never reach the mart', function () {
    // Arrange, Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(Revenue::query()->where('customer_id', 3)->exists())->toBeFalse();
});

it('rebuilds a table from scratch on a second sync', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    seedOrders([['customer_id' => 4, 'amount' => 70, 'status' => 'paid']]);

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(Revenue::query()->where('customer_id', 4)->value('total'))->toEqual(70)
        ->and(Revenue::query()->count())->toBe(3);
});

it('leaves no scratch relations behind after a rebuild', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    $leftovers = collect(DB::select("select name from sqlite_master where type in ('table', 'view') and name like '%\\_\\_%' escape '\\'"))
        ->pluck('name');

    expect($leftovers)->toBeEmpty();
});

it('recreates declared indexes on every rebuild', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    $indexes = DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'analytics_revenue'");

    expect($indexes)->toHaveCount(1);
});

it('builds upstream models when a single model is named', function () {
    // Arrange, Act
    $this->artisan('analytics:sync', ['model' => 'Revenue'])->assertSuccessful();

    // Assert
    expect(DB::table('analytics_stg_order')->count())->toBe(3);
});

it('skips upstream models when only is passed', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act, Assert
    $this->artisan('analytics:sync', ['model' => 'Revenue', '--only' => true])
        ->expectsOutputToContain('1 model synced')
        ->assertSuccessful();
});

it('fails on an unknown model instead of silently building nothing', function () {
    // Arrange, Act, Assert
    $this->artisan('analytics:sync', ['model' => 'NotAModel'])->assertFailed();
});

it('builds a date spine through the sqlite grammar', function () {
    // Arrange, Act
    $this->artisan('analytics:sync', ['model' => 'MonthSpine'])->assertSuccessful();

    // Assert
    expect(DB::table('analytics_month_spine')->orderBy('month')->pluck('month')->all())
        ->toBe(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01']);
});

it('records a run for every materialized model', function () {
    // Arrange, Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('analytics_runs')->pluck('model')->all())
        ->not->toContain(OrderTotals::class)
        ->and(DB::table('analytics_runs')->count())->toBe(3);
});

it('emits one tab separated line per model in porcelain mode', function () {
    // Arrange, Act
    Artisan::call('analytics:sync', ['--porcelain' => true]);

    // Assert
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    expect($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect(explode("\t", $line))->toHaveCount(3);
    }
});

it('reports rows and duration for a materialized model in porcelain mode', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    Artisan::call('analytics:sync', ['model' => 'Revenue', '--only' => true, '--porcelain' => true]);

    // Assert
    [$name, $rows, $duration] = explode("\t", trim(Artisan::output()));

    expect($name)->toBe('Revenue')
        ->and((int) $rows)->toBe(2)
        ->and($duration)->toBeNumeric();
});
