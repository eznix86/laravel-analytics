<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

class PostgresGrammar extends Grammar
{
    public function dateTrunc(string $unit, string $column): string
    {
        return "date_trunc('".$this->guardUnit($unit)."', {$column})";
    }

    public function dateAdd(string $unit, int $amount, string $column): string
    {
        return "({$column} + interval '{$amount} ".$this->guardUnit($unit)."')";
    }

    public function dateDiff(string $unit, string $start, string $end): string
    {
        return match ($this->guardUnit($unit)) {
            'day' => "(date_part('day', {$end}::timestamp - {$start}::timestamp))",
            'month' => "((date_part('year', {$end}::timestamp) - date_part('year', {$start}::timestamp)) * 12 + (date_part('month', {$end}::timestamp) - date_part('month', {$start}::timestamp)))",
            default => "(date_part('year', {$end}::timestamp) - date_part('year', {$start}::timestamp))",
        };
    }

    public function dateSpine(string $unit, string $start, string $end, string $as = 'spine'): string
    {
        $unit = $this->guardUnit($unit);

        return "(select generate_series({$this->dateTrunc($unit, $start)}, {$this->dateTrunc($unit, $end)}, interval '1 {$unit}')::date as d) as {$as}";
    }

    public function stringAgg(string $column, string $delimiter = ','): string
    {
        return "string_agg({$column}::text, '{$delimiter}')";
    }

    public function compileCreateView(string $view, string $sql): string
    {
        return "create or replace view {$view} as {$sql}";
    }

    public function replacesViewsAtomically(): bool
    {
        return true;
    }

    public function compileNotEqual(string $left, string $right): string
    {
        return "{$left} is distinct from {$right}";
    }

    /**
     * @return array<string, string>
     */
    protected function castTypes(): array
    {
        return [
            'integer' => 'integer',
            'bigint' => 'bigint',
            'decimal' => 'numeric',
            'numeric' => 'numeric',
            'real' => 'double precision',
            'text' => 'text',
            'date' => 'date',
            'timestamp' => 'timestamp',
            'boolean' => 'boolean',
        ];
    }
}
