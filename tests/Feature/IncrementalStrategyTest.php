<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Engines\NativeEngine;
use Eznix86\LaravelAnalytics\Engines\SyncResult;
use Eznix86\LaravelAnalytics\Exceptions\MissingUniqueKey;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Incremental\Events;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Incremental\Restated;
use Eznix86\LaravelAnalytics\Tests\Fixtures\StrategyAppend\Logged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('appends by default when no unique key is declared', function () {
    // Arrange
    $model = new Events;

    // Act
    $strategy = $model->incrementalStrategy();

    // Assert
    expect($strategy)->toBe(IncrementalStrategy::Append);
});

it('replaces by default once a unique key is declared', function () {
    // Arrange
    $model = new Restated;

    // Act
    $strategy = $model->incrementalStrategy();

    // Assert
    expect($strategy)->toBe(IncrementalStrategy::DeleteInsert);
});

it('appends even with a unique key when the model asks to', function () {
    // Arrange
    usingFixtures('StrategyAppend');

    seedOrders([['customer_id' => 1, 'amount' => 100, 'status' => 'paid']]);
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 2, 'amount' => 50, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(DB::table('analytics_logged')->count())->toBe(2)
        ->and((new Logged)->incrementalStrategy())->toBe(IncrementalStrategy::Append);
});

it('refuses to replace rows when nothing identifies them', function () {
    // Arrange
    usingFixtures('StrategyBroken');

    seedOrders([['customer_id' => 1, 'amount' => 100, 'status' => 'paid']]);
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 2, 'amount' => 50, 'status' => 'paid']]);

    $node = app(Resolver::class)->resolve()[0];

    // Act
    $build = fn (): SyncResult => app(NativeEngine::class)->sync($node, 'test-run');

    // Assert
    expect($build)->toThrow(MissingUniqueKey::class, 'unique key');
});

it('never leaves the relation short of the batch it is replacing', function () {
    // Arrange
    usingFixtures('Incremental');

    seedOrders([
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
    Artisan::call('analytics:sync');

    seedOrders([['customer_id' => 1, 'amount' => 900, 'status' => 'paid']]);

    // Act
    Artisan::call('analytics:sync');

    // Assert
    expect(DB::table('analytics_restated')->count())->toBe(2)
        ->and((int) DB::table('analytics_restated')->where('customer_id', 1)->value('total'))->toBe(1000);
});
