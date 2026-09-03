<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\RunStatus;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Resume\LateArriving;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Resume\StgOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    usingFixtures('Resume');

    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
});

function arriveLate(): void
{
    Schema::create('late_arriving', function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    DB::table('late_arriving')->insert([['label' => 'arrived']]);
}

it('stops at the first failure and reports which model broke', function () {
    // Arrange, Act
    $exitCode = Artisan::call('analytics:sync');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('LateArriving failed to build.')
        ->and($output)->toContain('--continue');
});

it('records the failure and the models that had already succeeded', function () {
    // Arrange, Act
    Artisan::call('analytics:sync');

    // Assert
    $runs = AnalyticsRun::query()->pluck('status', 'model');

    expect($runs)->toHaveCount(2)
        ->and($runs[StgOrder::class])->toBe(RunStatus::Success)
        ->and($runs[LateArriving::class])->toBe(RunStatus::Failed);
});

it('keeps the failure message so the run log explains itself', function () {
    // Arrange, Act
    Artisan::call('analytics:sync');

    // Assert
    $failure = AnalyticsRun::query()->where('status', RunStatus::Failed)->firstOrFail();

    expect($failure->error)->toContain('late_arriving');
});

it('skips the models that already succeeded when resuming', function () {
    // Arrange
    Artisan::call('analytics:sync');
    arriveLate();

    // Act
    $exitCode = Artisan::call('analytics:sync', ['--continue' => true]);
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('skipping 1 model')
        ->and($output)->not->toContain('StgOrder');
});

it('finishes the graph from where the failure stopped it', function () {
    // Arrange
    Artisan::call('analytics:sync');
    arriveLate();

    // Act
    Artisan::call('analytics:sync', ['--continue' => true]);

    // Assert
    expect(DB::table('analytics_late_arriving')->count())->toBe(2)
        ->and(DB::table('analytics_downstream')->count())->toBe(2);
});

it('records the resumed models under the same run id', function () {
    // Arrange
    Artisan::call('analytics:sync');
    arriveLate();

    // Act
    Artisan::call('analytics:sync', ['--continue' => true]);

    // Assert
    expect(AnalyticsRun::query()->distinct()->pluck('run_id'))->toHaveCount(1)
        ->and(AnalyticsRun::query()->where('status', RunStatus::Success)->count())->toBe(3);
});

it('says there is nothing to resume when the last run completed', function () {
    // Arrange
    arriveLate();
    Artisan::call('analytics:sync');

    // Act
    $exitCode = Artisan::call('analytics:sync', ['--continue' => true]);

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to resume');
});
