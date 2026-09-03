<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;

final class Aliased extends Expression
{
    public function __construct(
        private readonly Expression $expression,
        private readonly string $alias,
    ) {}

    public function render(Grammar $grammar): string
    {
        return $this->expression->render($grammar).' as '.$this->alias;
    }
}
