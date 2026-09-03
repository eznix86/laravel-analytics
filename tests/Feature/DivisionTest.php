<?php

declare(strict_types=1);

use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Illuminate\Support\Facades\DB;

it('divides in decimals on sqlite, where a numeric cast would silently truncate', function () {
    // Arrange
    $grammar = app(GrammarManager::class)->for('sqlite');

    // Act
    $expression = $grammar->cast('3', 'decimal(18,4)').' / 2 as ratio';
    $ratio = DB::selectOne("select {$expression}")->ratio;

    // Assert
    expect((float) $ratio)->toBe(1.5);
});
