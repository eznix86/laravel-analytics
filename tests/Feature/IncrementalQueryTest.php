<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Compilation\BatchWindow;
use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Batched;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Keyed;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Stream;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    usingFixtures('Fluent');

    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);
});

it('adds no high water mark on the build that creates the relation', function () {
    // Arrange
    $model = new Stream;

    // Act
    $sql = $model->compile()->sql;

    // Assert
    expect($sql)->not->toContain('max(id)');
});

it('compares past the high water mark with the operator the strategy requires', function (string $fixture, string $expected) {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $sql = (new $fixture)->compile()->sql;

    // Assert
    expect($sql)->toContain($expected);
})->with([
    'appending, so the boundary row is excluded' => [Stream::class, 'id > (select max(id)'],
    'replacing by key, so the boundary row is rebuilt' => [Keyed::class, ' >= (select max(month)'],
]);

it('compares the dimension expression rather than the alias, which no driver accepts in a where', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $where = substr((new Keyed)->compile()->sql, (int) strpos((new Keyed)->compile()->sql, 'where'));

    // Assert
    expect($where)->toContain('strftime')
        ->and($where)->not->toStartWith('where month >=');
});

it('drops the high water mark on a full refresh, so the relation is rebuilt from scratch', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $sql = app(Compiler::class)->compile(new Stream, true)->sql;

    // Assert
    expect($sql)->not->toContain('max(id)');
});

it('narrows a microbatch model to the batch being built without the model asking', function () {
    // Arrange
    $window = new BatchWindow(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01'));

    // Act
    $compiled = app(Compiler::class)->compile(new Batched, false, $window);

    // Assert
    expect($compiled->sql)->toContain('placed_on >= ?')
        ->and($compiled->sql)->toContain('placed_on < ?')
        ->and($compiled->bindings)->toBe(['2026-01-01 00:00:00', '2026-02-01 00:00:00']);
});

it('leaves a microbatch model unfiltered when it is compiled outside a batch', function () {
    // Arrange
    $model = new Batched;

    // Act
    $compiled = $model->compile();

    // Assert
    expect($compiled->sql)->not->toContain('placed_on >=')
        ->and($compiled->bindings)->toBe([]);
});

it('applies an incremental block only once the relation exists', function () {
    // Arrange
    $model = new Stream;
    $query = Query::from(Order::class)->select('id')
        ->whenIncremental(fn (Query $inner): Query => $inner->whereRaw('id > 100'));
    $compile = fn (): string => $query->compile(app(Context::class), $model->grammar(), $model)->sql;

    // Act
    $first = $compile();
    $this->artisan('analytics:sync')->assertSuccessful();
    $second = $compile();

    // Assert
    expect($first)->not->toContain('id > 100')
        ->and($second)->toContain('id > 100');
});
