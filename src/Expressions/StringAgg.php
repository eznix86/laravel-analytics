<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;

final class StringAgg extends Expression
{
    public function __construct(
        private readonly Expression|string $column,
        private readonly string $delimiter = ',',
    ) {}

    public function render(Grammar $grammar): string
    {
        return $grammar->stringAgg($this->operand($this->column, $grammar), $this->delimiter);
    }
}
