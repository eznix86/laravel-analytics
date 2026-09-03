<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\CircularDependency;
use Eznix86\LaravelAnalytics\Exceptions\ConnectionMismatch;
use Eznix86\LaravelAnalytics\Graph\Node;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Revenue;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\StgOrder;

it('discovers models using the Analytics trait and ignores plain Eloquent models', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $discovered = $resolver->discover();

    // Assert
    expect($discovered)->toContain(Revenue::class)
        ->and($discovered)->toContain(StgOrder::class)
        ->and($discovered)->not->toContain(Order::class);
});

it('places every model after the models it depends on', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $nodes = $resolver->resolve();

    // Assert
    $positions = [];

    foreach ($nodes as $position => $node) {
        $positions[$node->model] = $position;
    }

    $edges = 0;

    foreach ($nodes as $node) {
        foreach ($node->dependencies() as $dependency) {
            $edges++;
            expect($positions[$dependency])->toBeLessThan($positions[$node->model]);
        }
    }

    expect($edges)->toBeGreaterThan(0);
});

it('keeps ephemeral models out of the buildable set', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $nodes = $resolver->resolve();

    // Assert
    $buildable = array_map(
        static fn (Node $node): string => $node->name(),
        array_filter($nodes, static fn (Node $node): bool => $node->isBuildable()),
    );

    expect($buildable)->not->toContain('OrderTotals')
        ->and($buildable)->toContain('Revenue');
});

it('rejects a cycle between materialized models', function () {
    // Arrange
    usingFixtures('Cycle');
    $resolver = app(Resolver::class);

    // Act
    $resolve = fn (): array => $resolver->resolve();

    // Assert
    expect($resolve)->toThrow(CircularDependency::class);
});

it('rejects a cycle between ephemeral models before it recurses forever', function () {
    // Arrange
    usingFixtures('EphemeralCycle');
    $resolver = app(Resolver::class);

    // Act
    $resolve = fn (): array => $resolver->resolve();

    // Assert
    expect($resolve)->toThrow(CircularDependency::class);
});

it('rejects a model that reaches across connections', function () {
    // Arrange
    usingFixtures('Mismatch');
    $resolver = app(Resolver::class);

    // Act
    $resolve = fn (): array => $resolver->resolve();

    // Assert
    expect($resolve)->toThrow(ConnectionMismatch::class);
});

it('filters the resolved graph down to one connection', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $onWarehouse = $resolver->resolve('warehouse');
    $onDefault = $resolver->resolve('testing');

    // Assert
    expect($onWarehouse)->toBe([])
        ->and($onDefault)->toHaveCount(4);
});

it('treats an unset connection as the application default', function () {
    // Arrange
    usingFixtures('DefaultConnection');
    $resolver = app(Resolver::class);

    // Act
    $nodes = $resolver->resolve();

    // Assert
    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]->connection)->toBe('testing');
});

it('groups the build order into waves that can run together', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $levels = $resolver->levels();

    // Assert
    $names = array_map(
        static fn (array $level): array => array_map(static fn (Node $node): string => $node->name(), $level),
        $levels,
    );

    expect($names)->toBe([
        ['MonthSpine', 'StgOrder'],
        ['OrderTotals'],
        ['Revenue'],
    ]);
});

it('never places a model in the same wave as something it depends on', function () {
    // Arrange
    $resolver = app(Resolver::class);

    // Act
    $levels = $resolver->levels();

    // Assert
    $seen = [];

    foreach ($levels as $level) {
        foreach ($level as $node) {
            foreach ($node->dependencies() as $dependency) {
                expect($seen)->toHaveKey($dependency);
            }
        }

        foreach ($level as $node) {
            $seen[$node->model] = true;
        }
    }

    expect($levels)->toHaveCount(3);
});
