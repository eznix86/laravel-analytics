<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\UnsupportedDriver;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Eznix86\LaravelAnalytics\Grammars\MySqlGrammar;
use Eznix86\LaravelAnalytics\Grammars\PostgresGrammar;
use Eznix86\LaravelAnalytics\Grammars\SQLiteGrammar;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Expressions\MonthlyAmounts;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MariaDbGrammar as MariaDbQueryGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar as MySqlQueryGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar as PostgresQueryGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar as SQLiteQueryGrammar;
use Illuminate\Support\Facades\DB;

use function Eznix86\LaravelAnalytics\cast;
use function Eznix86\LaravelAnalytics\date_trunc;
use function Eznix86\LaravelAnalytics\raw;
use function Eznix86\LaravelAnalytics\string_agg;

it('renders one expression object differently for every driver', function () {
    // Arrange
    $expression = date_trunc('month', 'created_at');

    // Act
    $rendered = array_map(
        static fn ($grammar): string => $expression->render($grammar),
        [new PostgresGrammar, new MySqlGrammar, new SQLiteGrammar],
    );

    // Assert
    expect(array_unique($rendered))->toHaveCount(3)
        ->and($rendered[0])->toContain('date_trunc')
        ->and($rendered[1])->toContain('date_format')
        ->and($rendered[2])->toContain('strftime');
});

it('takes the driver from the query grammar Laravel passes into getValue', function (string $queryGrammar, string $expected) {
    // Arrange
    $expression = date_trunc('month', 'created_at');
    $grammar = new $queryGrammar(DB::connection());

    // Act
    $rendered = $expression->getValue($grammar);

    // Assert
    expect($rendered)->toContain($expected);
})->with([
    'postgres' => [PostgresQueryGrammar::class, 'date_trunc'],
    'mysql' => [MySqlQueryGrammar::class, 'date_format'],
    'sqlite' => [SQLiteQueryGrammar::class, 'strftime'],
]);

it('compiles inside a query builder that was never told which driver it is on', function () {
    // Arrange
    $query = DB::table('orders')->select(date_trunc('month', 'placed_on'));

    // Act
    $sql = $query->toSql();

    // Assert
    expect($sql)->toContain('strftime');
});

it('routes MariaDB to its own grammar rather than inheriting the MySQL one', function () {
    // Arrange
    $manager = app(GrammarManager::class);
    $manager->extend('mariadb', PostgresGrammar::class);

    // Act
    $resolved = $manager->fromQueryGrammar(new MariaDbQueryGrammar(DB::connection()));

    // Assert
    expect($resolved)->toBeInstanceOf(PostgresGrammar::class);
});

it('refuses a query grammar it has no analytics grammar for', function () {
    // Arrange
    $grammar = new class(DB::connection()) extends Grammar {};

    // Act
    $resolve = fn () => app(GrammarManager::class)->fromQueryGrammar($grammar);

    // Assert
    expect($resolve)->toThrow(UnsupportedDriver::class);
});

it('renders a nested expression from the inside out', function () {
    // Arrange
    $expression = cast(string_agg('name', ' | '), 'text');

    // Act
    $rendered = $expression->render(new PostgresGrammar);

    // Assert
    expect($rendered)->toStartWith('cast(')
        ->and($rendered)->toContain('string_agg')
        ->and($rendered)->toContain(' | ')
        ->and(strpos($rendered, 'string_agg'))->toBeGreaterThan(strpos($rendered, 'cast('));
});

it('substitutes rendered operands into a raw fragment', function () {
    // Arrange
    $expression = raw('%s / nullif(%s, 0)', cast('total', 'decimal(18,4)'), 'customers');

    // Act
    $rendered = $expression->render(new SQLiteGrammar);

    // Assert
    expect($rendered)->toBe('cast(total as real) / nullif(customers, 0)');
});

it('treats a percent sign as SQL rather than a placeholder when no operands are given', function () {
    // Arrange
    $expression = raw("name like '%sale%'");

    // Act
    $rendered = $expression->render(new SQLiteGrammar);

    // Assert
    expect($rendered)->toBe("name like '%sale%'");
});

it('refuses a raw fragment whose placeholders and operands disagree', function () {
    // Arrange
    $expression = raw('%s / %s', 'total');

    // Act
    $render = fn (): string => $expression->render(new SQLiteGrammar);

    // Assert
    expect($render)->toThrow(InvalidArgumentException::class);
});

it('appends an alias without disturbing the expression it wraps', function () {
    // Arrange
    $expression = date_trunc('day', 'created_at');

    // Act
    $aliased = $expression->as('day')->render(new SQLiteGrammar);

    // Assert
    expect($aliased)->toBe($expression->render(new SQLiteGrammar).' as day');
});

it('lets a model render expressions with its own connection grammar and no threading', function () {
    // Arrange
    usingFixtures('Expressions');

    // Act
    $sql = (new MonthlyAmounts)->compile()->sql;

    // Assert
    expect($sql)->toContain('strftime')
        ->and($sql)->toContain('cast(sum(amount) as real)')
        ->and($sql)->not->toContain('decimal(18,4)');
});
