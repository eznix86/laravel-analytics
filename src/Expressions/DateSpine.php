<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;

final class DateSpine extends Expression
{
    public function __construct(
        private readonly string $unit,
        private readonly Expression|string $start,
        private readonly Expression|string $end,
        private readonly string $as = 'spine',
    ) {}

    public function render(Grammar $grammar): string
    {
        return $grammar->dateSpine(
            $this->unit,
            $this->operand($this->start, $grammar),
            $this->operand($this->end, $grammar),
            $this->as,
        );
    }
}
