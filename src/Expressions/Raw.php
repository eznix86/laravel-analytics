<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Expressions;

use Eznix86\LaravelAnalytics\Grammars\Grammar;
use InvalidArgumentException;

final class Raw extends Expression
{
    /**
     * @var list<Expression|string>
     */
    private readonly array $operands;

    public function __construct(
        private readonly string $sql,
        Expression|string ...$operands,
    ) {
        $this->operands = array_values($operands);
    }

    public function render(Grammar $grammar): string
    {
        if ($this->operands === []) {
            return $this->sql;
        }

        $parts = explode('%s', $this->sql);
        $placeholders = count($parts) - 1;

        if ($placeholders !== count($this->operands)) {
            throw new InvalidArgumentException(sprintf(
                'Raw expression [%s] has %d placeholder(s) but %d operand(s) were given.',
                $this->sql,
                $placeholders,
                count($this->operands),
            ));
        }

        $sql = $parts[0];

        foreach ($this->operands($this->operands, $grammar) as $index => $rendered) {
            $sql .= $rendered.$parts[$index + 1];
        }

        return $sql;
    }
}
