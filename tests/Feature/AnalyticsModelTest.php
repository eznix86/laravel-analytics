<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\NotQueryable;
use Eznix86\LaravelAnalytics\Exceptions\ReadOnlyModel;
use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\MonthSpine;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\OrderTotals;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Revenue;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
    ]);
});

it('names its relation from the configured prefix', function () {
    // Arrange
    config()->set('analytics.prefix', 'dw_');

    // Act
    $table = (new Revenue)->getTable();

    // Assert
    expect($table)->toBe('dw_revenue');
});

it('prefers a schema over the prefix when one is configured', function () {
    // Arrange
    config()->set('analytics.schema', 'analytics');

    // Act
    $table = (new Revenue)->getTable();

    // Assert
    expect($table)->toBe('analytics.revenue');
});

it('refuses a write because the next sync would discard it', function () {
    // Arrange
    $revenue = new Revenue;
    $revenue->customer_id = 1;
    $revenue->total = 500;

    // Act
    $save = fn (): bool => $revenue->save();

    // Assert
    expect($save)->toThrow(ReadOnlyModel::class);
});

it('refuses a delete on a synced row', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();
    $row = Revenue::query()->firstOrFail();

    // Act
    $delete = fn (): ?bool => $row->delete();

    // Assert
    expect($delete)->toThrow(ReadOnlyModel::class);
});

it('refuses to query an ephemeral model because it has no relation', function () {
    // Arrange
    $query = fn (): int => OrderTotals::query()->count();

    // Act, Assert
    expect($query)->toThrow(NotQueryable::class);
});

it('reports a freshly synced model as current', function () {
    // Arrange, Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(Revenue::isStale())->toBeFalse()
        ->and(Revenue::lastSyncedAt())->not->toBeNull();
});

it('reports a model as stale once it passes its freshness window', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    AnalyticsRun::query()->update(['synced_at' => Carbon::now()->subDays(3)]);

    // Assert
    expect(Revenue::isStale())->toBeTrue();
});

it('never reports a model without a freshness window as stale', function () {
    // Arrange, Act, Assert
    expect(MonthSpine::isStale())->toBeFalse();
});

it('reports a never synced model with a freshness window as stale', function () {
    // Arrange, Act, Assert
    expect(Revenue::isStale())->toBeTrue();
});
