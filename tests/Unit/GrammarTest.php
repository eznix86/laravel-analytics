<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Exceptions\UnsupportedDriver;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Eznix86\LaravelAnalytics\Grammars\MySqlGrammar;
use Eznix86\LaravelAnalytics\Grammars\PostgresGrammar;
use Eznix86\LaravelAnalytics\Grammars\SQLiteGrammar;

it('translates the same truncation differently for every driver', function () {
    // Arrange
    $grammars = [new PostgresGrammar, new MySqlGrammar, new SQLiteGrammar];

    // Act
    $translations = array_map(
        static fn ($grammar): string => $grammar->dateTrunc('month', 'created_at'),
        $grammars,
    );

    // Assert
    expect(array_unique($translations))->toHaveCount(3)
        ->and($translations[0])->toContain('date_trunc')
        ->and($translations[1])->toContain('date_format')
        ->and($translations[2])->toContain('strftime');
});

it('rejects a date unit it cannot translate on every driver', function (object $grammar) {
    // Arrange
    $translate = fn (): string => $grammar->dateTrunc('fortnight', 'created_at');

    // Act, Assert
    expect($translate)->toThrow(InvalidArgumentException::class);
})->with([
    'postgres' => fn () => new PostgresGrammar,
    'mysql' => fn () => new MySqlGrammar,
    'sqlite' => fn () => new SQLiteGrammar,
]);

it('produces an aliased date spine that can be dropped straight into a from clause', function (object $grammar) {
    // Arrange
    $start = "'2026-01-01'";
    $end = "'2026-03-01'";

    // Act
    $spine = $grammar->dateSpine('month', $start, $end, 'months');

    // Assert
    expect($spine)->toStartWith('(')
        ->and($spine)->toEndWith(' as months')
        ->and(substr_count($spine, '('))->toBe(substr_count($spine, ')'));
})->with([
    'postgres' => fn () => new PostgresGrammar,
    'mysql' => fn () => new MySqlGrammar,
    'sqlite' => fn () => new SQLiteGrammar,
]);

it('reports that MySQL cannot swap relations inside a transaction', function () {
    // Arrange
    $mysql = new MySqlGrammar;
    $postgres = new PostgresGrammar;

    // Act, Assert
    expect($mysql->supportsTransactionalDdl())->toBeFalse()
        ->and($postgres->supportsTransactionalDdl())->toBeTrue();
});

it('names the drivers it cannot serve instead of failing silently', function () {
    // Arrange
    $manager = new GrammarManager;

    // Act
    $resolve = fn (): object => $manager->for('oracle');

    // Assert
    expect($resolve)->toThrow(UnsupportedDriver::class, 'sqlite');
});

it('translates a portable cast type into what each driver accepts', function () {
    // Arrange
    $grammars = [new PostgresGrammar, new MySqlGrammar, new SQLiteGrammar];

    // Act
    $casts = array_map(
        static fn ($grammar): string => $grammar->cast('debit', 'bigint'),
        $grammars,
    );

    // Assert
    expect($casts[0])->toBe('cast(debit as bigint)')
        ->and($casts[1])->toBe('cast(debit as signed)')
        ->and($casts[2])->toBe('cast(debit as integer)');
});

it('passes an unknown cast type through so driver specific types stay available', function () {
    // Arrange
    $postgres = new PostgresGrammar;

    // Act
    $cast = $postgres->cast('payload', 'jsonb');

    // Assert
    expect($cast)->toBe('cast(payload as jsonb)');
});

it('keeps a cast precision where the driver has sized types and drops it where it does not', function () {
    // Arrange
    $grammars = [new PostgresGrammar, new MySqlGrammar, new SQLiteGrammar];

    // Act
    $casts = array_map(
        static fn ($grammar): string => $grammar->cast('total', 'decimal(18,4)'),
        $grammars,
    );

    // Assert
    expect($casts[0])->toBe('cast(total as numeric(18,4))')
        ->and($casts[1])->toBe('cast(total as decimal(18,4))')
        ->and($casts[2])->toBe('cast(total as real)');
});
