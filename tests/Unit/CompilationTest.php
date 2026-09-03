<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\OutsideCompilation;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Builder\BuilderBacked;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Cte\BigSpenders;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\OrderTotals;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Revenue;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\StgOrder;

it('inlines an ephemeral dependency as a CTE instead of a relation', function () {
    // Arrange
    $revenue = new Revenue;

    // Act
    $compiled = $revenue->compile();

    // Assert
    expect($compiled->sql)->toContain('cte_order_totals as (')
        ->and($compiled->sql)->toContain('from cte_order_totals')
        ->and($compiled->sql)->not->toContain('analytics_order_totals');
});

it('resolves a materialized dependency to its relation name', function () {
    // Arrange
    $totals = new OrderTotals;

    // Act
    $compiled = $totals->compile();

    // Assert
    expect($compiled->sql)->toContain('from analytics_stg_order')
        ->and($compiled->sql)->not->toContain('cte_stg_order');
});

it('records dependencies that are only reachable through an ephemeral model', function () {
    // Arrange
    $revenue = new Revenue;

    // Act
    $compiled = $revenue->compile();

    // Assert
    expect($compiled->dependencies)->toContain(OrderTotals::class)
        ->and($compiled->dependencies)->toContain(StgOrder::class);
});

it('separates plain Eloquent models from analytics dependencies', function () {
    // Arrange
    $staging = new StgOrder;

    // Act
    $compiled = $staging->compile();

    // Assert
    expect($compiled->sources)->toBe([Order::class])
        ->and($compiled->dependencies)->toBe([]);
});

it('folds collected CTEs into SQL that already opens with a with clause', function () {
    // Arrange
    usingFixtures('Cte');

    // Act
    $compiled = (new BigSpenders)->compile();

    // Assert
    expect($compiled->sql)->toStartWith('with cte_filtered as (')
        ->and($compiled->sql)->toContain('totals as (select')
        ->and($compiled->sql)->not->toContain('with totals as');
});

it('collects bindings from a builder backed model', function () {
    // Arrange
    usingFixtures('Builder');

    // Act
    $compiled = (new BuilderBacked)->compile();

    // Assert
    expect($compiled->bindings)->toBe(['cancelled'])
        ->and($compiled->sql)->toContain('?');
});

it('refuses to resolve a ref outside of compilation', function () {
    // Arrange
    $revenue = new Revenue;

    // Act
    $computes = fn (): string => $revenue->computes();

    // Assert
    expect($computes)->toThrow(OutsideCompilation::class);
});
