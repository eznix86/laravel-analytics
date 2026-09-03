<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;

final class Cast extends Expression
{
    public function __construct(
        private readonly Expression|string $expression,
        private readonly string $type,
    ) {}

    public function render(Grammar $grammar): string
    {
        return $grammar->cast($this->operand($this->expression, $grammar), $this->type);
    }
}
