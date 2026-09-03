<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Exceptions\GroupedSelect;
use Eznix86\LaravelAnalytics\Exceptions\OutsideJoin;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Grained;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Joined;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent\Rollup;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Support\Facades\DB;

use function Eznix86\LaravelAnalytics\date_trunc;

beforeEach(function (): void {
    usingFixtures('Fluent');

    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 999, 'status' => 'cancelled'],
    ]);
});

it('groups by every dimension without the dimension being written twice', function () {
    // Arrange
    $model = new Rollup;

    // Act
    $sql = $model->compile()->sql;

    // Assert
    expect(substr_count($sql, 'strftime'))->toBe(2)
        ->and($sql)->toContain('group by')
        ->and(substr($sql, (int) strpos($sql, 'group by')))->toContain('customer_id')
        ->and($sql)->not->toContain('group by 1');
});

it('groups by the dimension expression rather than its alias, which no driver accepts', function () {
    // Arrange
    $model = new Rollup;

    // Act
    $groupBy = substr($model->compile()->sql, (int) strpos($model->compile()->sql, 'group by'));

    // Assert
    expect($groupBy)->not->toContain(' as month')
        ->and($groupBy)->toContain('strftime');
});

it('builds the rollup it describes', function () {
    // Arrange
    $this->artisan('analytics:sync')->assertSuccessful();

    // Act
    $rows = DB::table('analytics_rollup')->orderBy('customer_id')->get();

    // Assert
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->revenue)->toBe(300)
        ->and((int) $rows[0]->orders)->toBe(2)
        ->and((int) $rows[1]->revenue)->toBe(50);
});

it('passes a where value as a binding instead of interpolating it', function () {
    // Arrange
    $model = new Rollup;

    // Act
    $compiled = $model->compile();

    // Assert
    expect($compiled->bindings)->toBe(['cancelled'])
        ->and($compiled->sql)->toContain('status <> ?')
        ->and($compiled->sql)->not->toContain("'cancelled'");
});

it('joins on more than one condition', function () {
    // Arrange
    $model = new Joined;

    // Act
    $sql = $model->compile()->sql;

    // Assert
    expect($sql)->toContain('inner join')
        ->and($sql)->toContain('s.id = o.id and s.customer_id = o.customer_id');
});

it('groups by a grain that never reaches the select list', function () {
    // Arrange
    $model = new Grained;

    // Act
    $sql = $model->compile()->sql;

    // Assert
    expect(substr_count($sql, 'strftime'))->toBe(1)
        ->and(substr($sql, (int) strpos($sql, 'group by')))->toContain('strftime')
        ->and(substr($sql, 0, (int) strpos($sql, ' from ')))->not->toContain('strftime');
});

it('records the source of a fluent query as a dependency', function () {
    // Arrange
    $model = new Rollup;

    // Act
    $compiled = $model->compile();

    // Assert
    expect($compiled->sources)->toBe([Order::class])
        ->and($compiled->dependencies)->toBe([]);
});

it('refuses a plain select added to a query that already groups', function () {
    // Arrange
    $grouped = Query::from(Order::class)->per('customer_id');

    // Act
    $build = fn (): Query => $grouped->select('amount');

    // Assert
    expect($build)->toThrow(GroupedSelect::class);
});

it('refuses grouping added to a query that already selects plain columns', function () {
    // Arrange
    $selected = Query::from(Order::class)->select('amount');

    // Act
    $build = fn (): Query => $selected->per('customer_id');

    // Assert
    expect($build)->toThrow(GroupedSelect::class);
});

it('refuses a plain select added to a query that groups by a grain', function () {
    // Arrange
    $grained = Query::from(Order::class)->grain(date_trunc('month', 'placed_on'));

    // Act
    $build = fn (): Query => $grained->select('amount');

    // Assert
    expect($build)->toThrow(GroupedSelect::class);
});

it('refuses a join condition that has no join to attach to', function () {
    // Arrange
    $build = fn (): Query => Query::from(Order::class)->on('a.id', 'b.id');

    // Act, Assert
    expect($build)->toThrow(OutsideJoin::class);
});

it('leaves the query it was built from untouched', function () {
    // Arrange
    $base = Query::from(Order::class)->select('id');

    // Act
    $base->select('amount');
    $model = new Rollup;
    $sql = $base->compile(app(Context::class), $model->grammar(), $model)->sql;

    // Assert
    expect($sql)->toContain('select id from')
        ->and($sql)->not->toContain('amount');
});
