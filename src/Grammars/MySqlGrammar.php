<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

class MySqlGrammar extends Grammar
{
    public function dateTrunc(string $unit, string $column): string
    {
        return match ($this->guardUnit($unit)) {
            'day' => "date({$column})",
            'month' => "date_format({$column}, '%Y-%m-01')",
            default => "date_format({$column}, '%Y-01-01')",
        };
    }

    public function dateAdd(string $unit, int $amount, string $column): string
    {
        return "date_add({$column}, interval {$amount} ".$this->guardUnit($unit).')';
    }

    public function dateDiff(string $unit, string $start, string $end): string
    {
        return match ($this->guardUnit($unit)) {
            'day' => "datediff({$end}, {$start})",
            'month' => "timestampdiff(month, {$start}, {$end})",
            default => "timestampdiff(year, {$start}, {$end})",
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
            ."select date_add(d, interval 1 {$unit}) from analytics_spine where d < {$last}"
            .') select d from analytics_spine)'." as {$as}";
    }

    public function stringAgg(string $column, string $delimiter = ','): string
    {
        return "group_concat({$column} separator '{$delimiter}')";
    }

    public function compileNotEqual(string $left, string $right): string
    {
        return "not ({$left} <=> {$right})";
    }

    /**
     * @return array<string, string>
     */
    protected function castTypes(): array
    {
        return [
            'integer' => 'signed',
            'bigint' => 'signed',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'real' => 'decimal',
            'text' => 'char',
            'date' => 'date',
            'timestamp' => 'datetime',
            'boolean' => 'signed',
        ];
    }

    /**
     * MySQL renames every relation in the list in one statement, which no other
     * driver here does, so the swap needs no transaction around it.
     *
     * @param  list<array{string, string}>  $renames
     * @return list<string>
     */
    public function compileRenameTables(array $renames): array
    {
        $pairs = implode(', ', array_map(
            static fn (array $rename): string => "{$rename[0]} to {$rename[1]}",
            $renames,
        ));

        return ["rename table {$pairs}"];
    }

    public function compileCreateView(string $view, string $sql): string
    {
        return "create or replace view {$view} as {$sql}";
    }

    public function replacesViewsAtomically(): bool
    {
        return true;
    }

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }
}
