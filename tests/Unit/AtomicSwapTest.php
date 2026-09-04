<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Grammars\MySqlGrammar;
use Eznix86\LaravelAnalytics\Grammars\PostgresGrammar;
use Eznix86\LaravelAnalytics\Grammars\SQLiteGrammar;

it('swaps a table in one statement on MySQL, which has no transactional DDL to fall back on', function () {
    // Arrange
    $grammar = new MySqlGrammar;

    // Act
    $statements = $grammar->compileRenameTables([['revenue', 'revenue__old'], ['revenue__tmp', 'revenue']]);

    // Assert
    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toBe('rename table revenue to revenue__old, revenue__tmp to revenue')
        ->and($grammar->supportsTransactionalDdl())->toBeFalse();
});

it('swaps a table with one statement per rename where a transaction can wrap them', function (object $grammar) {
    // Arrange
    $renames = [['revenue', 'revenue__old'], ['revenue__tmp', 'revenue']];

    // Act
    $statements = $grammar->compileRenameTables($renames);

    // Assert
    expect($statements)->toHaveCount(2)
        ->and($grammar->supportsTransactionalDdl())->toBeTrue();
})->with([
    'postgres' => fn () => new PostgresGrammar,
    'sqlite' => fn () => new SQLiteGrammar,
]);

it('replaces a view without dropping it first where the driver allows it', function (object $grammar, bool $atomic) {
    // Arrange
    $sql = 'select 1 as one';

    // Act
    $create = $grammar->compileCreateView('analytics_stg', $sql);

    // Assert
    expect($grammar->replacesViewsAtomically())->toBe($atomic)
        ->and(str_contains($create, 'or replace'))->toBe($atomic);
})->with([
    'postgres' => [fn () => new PostgresGrammar, true],
    'mysql' => [fn () => new MySqlGrammar, true],
    'sqlite' => [fn () => new SQLiteGrammar, false],
]);
