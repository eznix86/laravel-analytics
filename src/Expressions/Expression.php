<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar as QueryGrammar;

abstract class Expression implements ExpressionContract
{
    abstract public function render(Grammar $grammar): string;

    public function getValue(QueryGrammar $grammar): string
    {
        return $this->render(app(GrammarManager::class)->fromQueryGrammar($grammar));
    }

    public function as(string $alias): Aliased
    {
        return new Aliased($this, $alias);
    }

    protected function operand(self|string $value, Grammar $grammar): string
    {
        return $value instanceof self ? $value->render($grammar) : $value;
    }

    /**
     * @param  list<self|string>  $values
     * @return list<string>
     */
    protected function operands(array $values, Grammar $grammar): array
    {
        return array_map(
            fn (self|string $value): string => $this->operand($value, $grammar),
            $values,
        );
    }
}
