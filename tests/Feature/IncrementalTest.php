<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\IncrementalWindowFunction;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Incremental\Events;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    usingFixtures('Incremental');

    seedOrders([
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
});

it('builds the whole table on the first run', function () {
    // Arrange, Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('analytics_events')->count())->toBe(2);
});

it('adds only the new rows on the next run', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    seedOrders([['customer_id' => 3, 'amount' => 25, 'status' => 'paid']]);

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('analytics_events')->count())->toBe(3)
        ->and(DB::table('analytics_events')->where('customer_id', 3)->exists())->toBeTrue();
});

it('reports only the appended row count, not the whole table', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    seedOrders([['customer_id' => 3, 'amount' => 25, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync', ['model' => 'Events', '--only' => true, '--porcelain' => true]);

    // Assert
    [, $rows] = explode("\t", trim(Artisan::output()));

    expect((int) $rows)->toBe(1);
});

it('leaves an untouched incremental model alone when nothing new arrives', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('analytics_events')->count())->toBe(2);
});

it('replaces matching rows when a unique key is declared', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    seedOrders([['customer_id' => 1, 'amount' => 900, 'status' => 'paid']]);

    // Act
    $this->artisan('analytics:sync')->assertSuccessful();

    // Assert
    expect(DB::table('analytics_restated')->count())->toBe(2)
        ->and((int) DB::table('analytics_restated')->where('customer_id', 1)->value('total'))->toBe(1000);
});

it('rebuilds from scratch under full refresh', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    DB::table('analytics_events')->insert([['id' => 999, 'customer_id' => 9, 'amount' => 1]]);

    // Act
    $this->artisan('analytics:sync', ['--full-refresh' => true])->assertSuccessful();

    // Assert
    expect(DB::table('analytics_events')->count())->toBe(2)
        ->and(DB::table('analytics_events')->where('id', 999)->exists())->toBeFalse();
});

it('shows that a run is appending rather than rebuilding', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    Artisan::call('analytics:graph');

    // Assert
    expect(Artisan::output())->toContain('incremental append');
});

it('compiles without the incremental filter on the first run', function () {
    // Arrange
    $compiled = (new Events)->compile();

    // Act, Assert
    expect($compiled->sql)->not->toContain('max(id)');
});

it('compiles with the incremental filter once the relation exists', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $compiled = (new Events)->compile();

    // Assert
    expect($compiled->sql)->toContain('max(id)');
});

it('refuses an incremental model that uses a window function', function () {
    // Arrange
    usingFixtures('IncrementalWindow');

    // Act
    $resolve = fn (): array => app(Resolver::class)->resolve();

    // Assert
    expect($resolve)->toThrow(IncrementalWindowFunction::class, 'boundary');
});

it('allows a window function once the model says it has been reviewed', function () {
    // Arrange
    usingFixtures('IncrementalWindowAllowed');

    // Act
    $nodes = app(Resolver::class)->resolve();

    // Assert
    expect($nodes)->toHaveCount(1);
});
