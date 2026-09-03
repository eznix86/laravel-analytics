<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\SnapshotHistory;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    usingFixtures('Snapshot');

    seedOrders([
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
});

function history(): Collection
{
    return DB::table('analytics_customer_history')->orderBy('customer_id')->orderBy('valid_from')->get();
}

it('opens one version per row on the first build', function () {
    // Arrange, Act
    Artisan::call('analytics:sync');

    // Assert
    expect(history())->toHaveCount(2)
        ->and(history()->every(fn ($row): bool => $row->valid_to === null))->toBeTrue();
});

it('records nothing new when the source has not changed', function () {
    // Arrange
    Artisan::call('analytics:sync');

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(history())->toHaveCount(2);
});

it('closes the old version and opens a new one when a row changes', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 1, 'amount' => 400, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    $versions = history()->where('customer_id', 1)->values();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->valid_to)->not->toBeNull()
        ->and((int) $versions[0]->total)->toBe(100)
        ->and($versions[1]->valid_to)->toBeNull()
        ->and((int) $versions[1]->total)->toBe(500);
});

it('leaves unchanged rows with a single open version', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 1, 'amount' => 400, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(history()->where('customer_id', 2))->toHaveCount(1);
});

it('opens a version for a row the source has never had before', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 3, 'amount' => 75, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(history()->where('customer_id', 3))->toHaveCount(1)
        ->and(history())->toHaveCount(3);
});

it('reports how many versions a run opened', function () {
    // Arrange
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 1, 'amount' => 400, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync', ['model' => 'CustomerHistory', '--only' => true, '--porcelain' => true]);

    // Assert
    [, $rows] = explode("\t", trim(Artisan::output()));

    expect((int) $rows)->toBe(1);
});

it('keeps every closed version so the history can be read back', function () {
    // Arrange
    Artisan::call('analytics:sync');
    seedOrders([['customer_id' => 1, 'amount' => 400, 'status' => 'paid']]);
    Artisan::call('analytics:sync');
    seedOrders([['customer_id' => 1, 'amount' => 1000, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    $totals = history()->where('customer_id', 1)->pluck('total')->map(intval(...))->values()->all();

    expect($totals)->toBe([100, 500, 1500])
        ->and(history()->where('customer_id', 1)->whereNull('valid_to'))->toHaveCount(1);
});

it('refuses a full refresh aimed straight at a snapshot', function () {
    // Arrange
    Artisan::call('analytics:sync');

    // Act
    $sync = fn (): int => Artisan::call('analytics:sync', [
        'model' => 'CustomerHistory',
        '--full-refresh' => true,
    ]);

    // Assert
    expect($sync)->toThrow(SnapshotHistory::class, 'discard history');
});

it('leaves snapshots alone when the whole graph is refreshed', function () {
    // Arrange
    Artisan::call('analytics:sync');
    seedOrders([['customer_id' => 1, 'amount' => 400, 'status' => 'paid']]);

    // Act
    $exitCode = Artisan::call('analytics:sync', ['--full-refresh' => true]);
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('snapshot left alone')
        ->and(history())->toHaveCount(2);
});

it('refuses a snapshot with nothing identifying its rows', function () {
    // Arrange
    usingFixtures('SnapshotKeyless');

    // Act
    $resolve = fn (): array => app(Resolver::class)->resolve();

    // Assert
    expect($resolve)->toThrow(SnapshotHistory::class, 'uniqueKey');
});
