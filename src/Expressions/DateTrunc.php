<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;

final class DateTrunc extends Expression
{
    public function __construct(
        private readonly string $unit,
        private readonly Expression|string $column,
    ) {}

    public function render(Grammar $grammar): string
    {
        return $grammar->dateTrunc($this->unit, $this->operand($this->column, $grammar));
    }
}
