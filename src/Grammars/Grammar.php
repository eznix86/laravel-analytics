<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Grammars;

use InvalidArgumentException;

abstract class Grammar
{
    /**
     * Units every driver grammar is required to support.
     *
     * @var list<string>
     */
    public const array UNITS = ['day', 'month', 'year'];

    abstract public function dateTrunc(string $unit, string $column): string;

    abstract public function dateAdd(string $unit, int $amount, string $column): string;

    abstract public function dateDiff(string $unit, string $start, string $end): string;

    abstract public function dateSpine(string $unit, string $start, string $end, string $as = 'spine'): string;

    abstract public function stringAgg(string $column, string $delimiter = ','): string;

    /**
     * Portable type names are translated per driver; anything else is passed through
     * verbatim so a driver-specific type stays available.
     */
    public function cast(string $expression, string $type): string
    {
        return "cast({$expression} as ".$this->castType($type).')';
    }

    /**
     * @return array<string, string>
     */
    abstract protected function castTypes(): array;

    protected function castType(string $type): string
    {
        $base = strtolower(trim($type));
        $precision = '';

        if (preg_match('/^([a-z ]+?)\s*(\(.+\))$/', $base, $matches) === 1) {
            $base = $matches[1];
            $precision = $matches[2];
        }

        $types = $this->castTypes();

        if (! isset($types[$base])) {
            return $type;
        }

        return $types[$base].($this->keepsPrecision() ? $precision : '');
    }

    /**
     * SQLite has no sized types, so a precision would make the cast invalid there.
     */
    protected function keepsPrecision(): bool
    {
        return true;
    }

    public function compileCreateTableAs(string $table, string $sql): string
    {
        return "create table {$table} as {$sql}";
    }

    public function compileCreateView(string $view, string $sql): string
    {
        return "create view {$view} as {$sql}";
    }

    public function compileDropView(string $view): string
    {
        return "drop view if exists {$view}";
    }

    public function compileDropTable(string $table): string
    {
        return "drop table if exists {$table}";
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return "alter table {$from} rename to {$to}";
    }

    /**
     * A driver that can rename several relations in one statement swaps atomically on
     * its own. Everything else needs the renames wrapped in a transaction.
     *
     * @param  list<array{string, string}>  $renames
     * @return list<string>
     */
    public function compileRenameTables(array $renames): array
    {
        return array_map(
            fn (array $rename): string => $this->compileRenameTable($rename[0], $rename[1]),
            $renames,
        );
    }

    /**
     * Whether replacing a view leaves no moment where it does not exist.
     */
    public function replacesViewsAtomically(): bool
    {
        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    public function compileCreateIndex(string $table, array $columns, string $name): string
    {
        return "create index {$name} on {$table} (".implode(', ', $columns).')';
    }

    /**
     * Rows in the target that the staging table is about to replace.
     *
     * Referred to by its unqualified name rather than an alias, because alias support in
     * DELETE differs between drivers while the implicit name works on all of them.
     *
     * @param  list<string>  $uniqueKey
     */
    public function compileDeleteMatching(string $table, string $staging, array $uniqueKey): string
    {
        $target = $this->unqualified($table);

        $conditions = implode(' and ', array_map(
            static fn (string $column): string => "staging.{$column} = {$target}.{$column}",
            $uniqueKey,
        ));

        return "delete from {$table} where exists (select 1 from {$staging} as staging where {$conditions})";
    }

    /**
     * @param  list<string>  $columns
     */
    public function compileInsertFrom(string $table, string $staging, array $columns): string
    {
        $list = implode(', ', $columns);

        return "insert into {$table} ({$list}) select {$list} from {$staging}";
    }

    /**
     * Null safe inequality, which every driver spells differently.
     */
    abstract public function compileNotEqual(string $left, string $right): string;

    /**
     * The first build of a snapshot: the model's current state, opened as of now.
     */
    public function compileSnapshotCreate(string $table, string $sql, string $timestamp): string
    {
        return "create table {$table} as select snapshot_source.*, "
            .$this->cast("'{$timestamp}'", 'timestamp').' as valid_from, '
            .$this->cast('null', 'timestamp').' as valid_to '
            ."from ({$sql}) as snapshot_source";
    }

    /**
     * Close the open version of every row whose tracked columns have changed.
     *
     * @param  list<string>  $uniqueKey
     * @param  list<string>  $checkColumns
     */
    public function compileSnapshotClose(string $table, string $staging, array $uniqueKey, array $checkColumns, string $timestamp): string
    {
        $target = $this->unqualified($table);

        $matches = implode(' and ', array_map(
            static fn (string $column): string => "incoming.{$column} = {$target}.{$column}",
            $uniqueKey,
        ));

        $changed = implode(' or ', array_map(
            fn (string $column): string => $this->compileNotEqual("{$target}.{$column}", "incoming.{$column}"),
            $checkColumns,
        ));

        return "update {$table} set valid_to = ".$this->cast("'{$timestamp}'", 'timestamp')
            ." where {$target}.valid_to is null and exists ("
            ."select 1 from {$staging} as incoming where {$matches} and ({$changed})"
            .')';
    }

    /**
     * Open a version for every row that has none, which is both the changed rows
     * just closed and the ones the model has never seen.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $uniqueKey
     */
    public function compileSnapshotInsert(string $table, string $staging, array $columns, array $uniqueKey, string $timestamp): string
    {
        $list = implode(', ', $columns);

        $selected = implode(', ', array_map(
            static fn (string $column): string => "incoming.{$column}",
            $columns,
        ));

        $matches = implode(' and ', array_map(
            static fn (string $column): string => "opened.{$column} = incoming.{$column}",
            $uniqueKey,
        ));

        return "insert into {$table} ({$list}, valid_from, valid_to) "
            ."select {$selected}, ".$this->cast("'{$timestamp}'", 'timestamp').', null '
            ."from {$staging} as incoming where not exists ("
            ."select 1 from {$table} as opened where {$matches} and opened.valid_to is null"
            .')';
    }

    protected function unqualified(string $table): string
    {
        return str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;
    }

    /**
     * Clear a batch's window so the rebuilt batch replaces it rather than doubling it.
     */
    public function compileDeleteWindow(string $table, string $column, string $start, string $end): string
    {
        return "delete from {$table} where {$column} >= '{$start}' and {$column} < '{$end}'";
    }

    public function compileAddColumn(string $table, string $column, string $type): string
    {
        return "alter table {$table} add column {$column} {$type}";
    }

    public function compileDropColumn(string $table, string $column): string
    {
        return "alter table {$table} drop column {$column}";
    }

    /**
     * MySQL commits implicitly on DDL, so its swap cannot be wrapped in a transaction.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    protected function guardUnit(string $unit): string
    {
        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported date unit [%s]. Supported units are: %s.',
                $unit,
                implode(', ', self::UNITS),
            ));
        }

        return $unit;
    }
}
