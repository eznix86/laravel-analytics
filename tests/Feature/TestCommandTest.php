<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\NotQueryable;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Eznix86\LaravelAnalytics\Testing\Result;
use Eznix86\LaravelAnalytics\Testing\Runner;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\MonthSpine;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\OrderTotals;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Revenue;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\StgOrder;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Unbuilt\Checked;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Unbuilt\Unchecked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedOrders([
        ['customer_id' => 1, 'amount' => 200, 'status' => 'paid'],
        ['customer_id' => 1, 'amount' => 100, 'status' => 'paid'],
        ['customer_id' => 2, 'amount' => 50, 'status' => 'paid'],
    ]);

    $this->artisan('analytics:sync')->assertSuccessful();
});

it('passes every declared expectation on freshly built data', function () {
    // Arrange, Act
    $exitCode = Artisan::call('analytics:test');
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(0)
        ->and($output)->toContain('expectations passed')
        ->and($output)->not->toContain('FAIL');
});

it('fails when a uniqueness expectation is broken', function () {
    // Arrange
    DB::table('analytics_revenue')->insert([['customer_id' => 1, 'total' => 999]]);

    // Act
    $exitCode = Artisan::call('analytics:test', ['model' => 'Revenue']);
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('customer_id is unique')
        ->and($output)->toContain('FAIL');
});

it('counts the rows that break an expectation', function () {
    // Arrange
    DB::table('analytics_revenue')->insert([
        ['customer_id' => 7, 'total' => -1],
        ['customer_id' => 8, 'total' => -2],
    ]);

    // Act
    $failures = app(Runner::class)->failures(new Revenue);

    // Assert
    $expression = array_values(array_filter(
        $failures,
        static fn (Result $result): bool => str_contains($result->expectation->describe(), 'total > 0'),
    ));

    expect($expression)->toHaveCount(1)
        ->and($expression[0]->offendingRows)->toBe(2);
});

it('detects a value outside the accepted set', function () {
    // Arrange
    DB::table('analytics_month_spine')->insert([['month' => '2027-12-01']]);

    // Act
    $failures = app(Runner::class)->failures(new MonthSpine);

    // Assert
    expect($failures)->toHaveCount(1)
        ->and($failures[0]->expectation->describe())->toContain('is one of')
        ->and($failures[0]->offendingRows)->toBe(1);
});

it('detects an orphaned reference', function () {
    // Arrange
    DB::table('analytics_revenue')->insert([['customer_id' => 404, 'total' => 10]]);

    // Act
    $failures = app(Runner::class)->failures(new Revenue);

    // Assert
    $orphans = array_values(array_filter(
        $failures,
        static fn (Result $result): bool => str_contains($result->expectation->describe(), 'exists in'),
    ));

    expect($orphans)->toHaveCount(1)
        ->and($orphans[0]->offendingRows)->toBe(1);
});

it('refuses to check an ephemeral model', function () {
    // Arrange
    $check = fn (): array => app(Runner::class)->run(new OrderTotals);

    // Act, Assert
    expect($check)->toThrow(NotQueryable::class);
});

it('says so when no model declares an expectation', function () {
    // Arrange
    usingFixtures('Cte');

    // Act
    $exitCode = Artisan::call('analytics:test');

    // Assert
    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No expectations declared');
});

it('describes an expectation in words rather than SQL', function () {
    // Arrange
    $unique = Expectation::unique('customer_id', 'month');

    // Act
    $description = $unique->describe();

    // Assert
    expect($description)->toBe('customer_id, month are unique together');
});

it('reports a model whose relation was never built instead of throwing', function () {
    // Arrange
    usingFixtures('Unbuilt');

    // Act
    $exitCode = Artisan::call('analytics:test', ['model' => 'Checked']);
    $output = Artisan::output();

    // Assert
    expect($exitCode)->toBe(1)
        ->and($output)->toContain('NOT BUILT')
        ->and($output)->toContain('analytics:sync');
});

it('refuses to check expectations against a relation that does not exist', function () {
    // Arrange
    usingFixtures('Unbuilt');
    $check = fn (): array => app(Runner::class)->run(new Checked);

    // Act, Assert
    expect($check)->toThrow(NotQueryable::class);
});

it('checks nothing rather than complaining when an unbuilt model declares no expectations', function () {
    // Arrange
    usingFixtures('Unbuilt');

    // Act
    $results = app(Runner::class)->run(new Unchecked);

    // Assert
    expect($results)->toBe([]);
});

it('treats a view backed model as built, which hasTable alone does not report', function () {
    // Arrange
    $view = new StgOrder;

    // Act
    $results = app(Runner::class)->run($view);

    // Assert
    expect($results)->not->toBeEmpty()
        ->and(array_filter($results, static fn (Result $result): bool => ! $result->passed()))->toBe([]);
});
