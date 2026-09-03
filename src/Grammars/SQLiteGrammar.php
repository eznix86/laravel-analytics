<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

class SQLiteGrammar extends Grammar
{
    public function dateTrunc(string $unit, string $column): string
    {
        return match ($this->guardUnit($unit)) {
            'day' => "date({$column})",
            'month' => "strftime('%Y-%m-01', {$column})",
            default => "strftime('%Y-01-01', {$column})",
        };
    }

    public function dateAdd(string $unit, int $amount, string $column): string
    {
        return "date({$column}, '{$amount} ".$this->guardUnit($unit)."')";
    }

    public function dateDiff(string $unit, string $start, string $end): string
    {
        return match ($this->guardUnit($unit)) {
            'day' => "(julianday({$end}) - julianday({$start}))",
            'month' => "((strftime('%Y', {$end}) - strftime('%Y', {$start})) * 12 + (strftime('%m', {$end}) - strftime('%m', {$start})))",
            default => "(strftime('%Y', {$end}) - strftime('%Y', {$start}))",
        };
    }

    public function dateSpine(string $unit, string $start, string $end, string $as = 'spine'): string
    {
        $unit = $this->guardUnit($unit);
        $first = $this->dateTrunc($unit, $start);
        $last = $this->dateTrunc($unit, $end);

        return '(with recursive analytics_spine (d) as ('
            ."select {$first} "
            .'union all '
            ."select date(d, '+1 {$unit}') from analytics_spine where d < {$last}"
            .') select d from analytics_spine)'." as {$as}";
    }

    public function stringAgg(string $column, string $delimiter = ','): string
    {
        return "group_concat({$column}, '{$delimiter}')";
    }

    protected function keepsPrecision(): bool
    {
        return false;
    }

    public function compileNotEqual(string $left, string $right): string
    {
        return "{$left} is not {$right}";
    }

    /**
     * @return array<string, string>
     */
    protected function castTypes(): array
    {
        return [
            'integer' => 'integer',
            'bigint' => 'integer',
            'decimal' => 'real',
            'numeric' => 'real',
            'real' => 'real',
            'text' => 'text',
            'date' => 'text',
            'timestamp' => 'text',
            'boolean' => 'integer',
        ];
    }

    public function compileDropView(string $view): string
    {
        return "drop view if exists {$view}";
    }
}
