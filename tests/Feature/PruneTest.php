<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\RunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

function recordRun(string $model, Carbon $syncedAt): void
{
    AnalyticsRun::query()->create([
        'run_id' => (string) Str::ulid(),
        'model' => $model,
        'materialization' => 'table',
        'status' => RunStatus::Success,
        'rows' => 1,
        'duration_ms' => 1,
        'synced_at' => $syncedAt,
    ]);
}

beforeEach(function (): void {
    recordRun('Ancient', Carbon::now()->subYears(3));
    recordRun('Old', Carbon::now()->subMonths(14));
    recordRun('Recent', Carbon::now()->subDays(2));
});

it('removes runs older than the retention window and keeps the rest', function () {
    // Arrange
    config()->set('analytics.retention', '1 year');

    // Act
    $exitCode = Artisan::call('analytics:prune');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(AnalyticsRun::query()->pluck('model')->all())->toBe(['Recent']);
});

it('reports how many runs it removed', function () {
    // Arrange
    config()->set('analytics.retention', '1 year');

    // Act
    Artisan::call('analytics:prune');

    // Assert
    expect(Artisan::output())->toContain('2 runs older than 1 year removed');
});

it('honours a shorter retention window', function () {
    // Arrange
    config()->set('analytics.retention', '1 day');

    // Act
    Artisan::call('analytics:prune');

    // Assert
    expect(AnalyticsRun::query()->count())->toBe(0);
});

it('keeps every run when no retention window is configured', function () {
    // Arrange
    config()->set('analytics.retention', null);

    // Act
    $exitCode = Artisan::call('analytics:prune');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No retention window configured')
        ->and(AnalyticsRun::query()->count())->toBe(3);
});

it('prunes nothing through the model when retention is unset', function () {
    // Arrange
    config()->set('analytics.retention', null);

    // Act
    $pruned = (new AnalyticsRun)->pruneAll();

    // Assert
    expect($pruned)->toBe(0)
        ->and(AnalyticsRun::query()->count())->toBe(3);
});

it('prunes through Laravel model:prune as well', function () {
    // Arrange
    config()->set('analytics.retention', '1 year');

    // Act
    Artisan::call('model:prune', ['--model' => [AnalyticsRun::class]]);

    // Assert
    expect(AnalyticsRun::query()->pluck('model')->all())->toBe(['Recent']);
});

it('skips a connection that has no run log table', function () {
    // Arrange
    config()->set('analytics.retention', '1 year');

    // Act
    $exitCode = Artisan::call('analytics:prune', ['--connection' => 'warehouse']);

    // Assert
    expect($exitCode)->toBe(0)
        ->and(AnalyticsRun::query()->count())->toBe(3);
});
