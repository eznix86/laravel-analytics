<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Engines;

use Eznix86\LaravelAnalytics\Compilation\BatchWindow;
use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Exceptions\InvalidImport;
use Eznix86\LaravelAnalytics\Exceptions\MissingBatchBegin;
use Eznix86\LaravelAnalytics\Exceptions\MissingUniqueKey;
use Eznix86\LaravelAnalytics\Exceptions\SchemaChanged;
use Eznix86\LaravelAnalytics\Graph\Node;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\RunStatus;
use Eznix86\LaravelAnalytics\SchemaChange;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class NativeEngine
{
    public function __construct(protected Compiler $compiler) {}

    /**
     * Set by analytics:sync --event-time-start / --event-time-end to rebuild a range.
     */
    protected ?Carbon $eventTimeStart = null;

    protected ?Carbon $eventTimeEnd = null;

    public function backfill(?Carbon $start, ?Carbon $end): static
    {
        $this->eventTimeStart = $start;
        $this->eventTimeEnd = $end;

        return $this;
    }

    public function sync(Node $node, string $runId): SyncResult
    {
        $startedAt = hrtime(true);

        try {
            $rows = match (true) {
                $node->materialization === Materialization::Ephemeral => null,
                $node->materialization === Materialization::View => $this->replaceView($node),
                $node->materialization === Materialization::Snapshot => $this->snapshotVersions($node),
                $node->materialization === Materialization::Microbatch => $this->buildBatches($node),
                $node->materialization === Materialization::Import => $this->importRows($node),
                $node->appending => $this->appendRows($node),
                default => $this->swapTable($node),
            };
        } catch (Throwable $failure) {
            $this->record($node, $runId, RunStatus::Failed, null, $this->elapsed($startedAt), $failure->getMessage());

            throw $failure;
        }

        $durationMs = $this->elapsed($startedAt);

        $this->record($node, $runId, RunStatus::Success, $rows, $durationMs, null);

        return new SyncResult($node, $rows, $durationMs);
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    protected function replaceView(Node $node): ?int
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();
        $view = $this->physical($connection, $model->getTable());

        $sql = $connection->getQueryGrammar()->substituteBindingsIntoRawSql(
            $node->compiled->sql,
            $connection->prepareBindings($node->compiled->bindings),
        );

        $connection->statement($grammar->compileDropView($view));
        $connection->statement($grammar->compileCreateView($view, $sql));

        return null;
    }

    protected function swapTable(Node $node): int
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();

        $table = $model->getTable();
        $schema = str_contains($table, '.') ? Str::beforeLast($table, '.').'.' : '';
        $name = $connection->getTablePrefix().Str::afterLast($table, '.');

        $token = Str::lower(Str::random(8));
        $temporary = $schema.$name.'__'.$token;
        $replaced = $name.'__old_'.$token;

        $connection->statement(
            $grammar->compileCreateTableAs($temporary, $node->compiled->sql),
            $node->compiled->bindings,
        );

        $this->createIndexes($node, $temporary, $name.'__'.$token);

        $rows = (int) $connection->selectOne("select count(*) as aggregate from {$temporary}")->aggregate;

        $existed = $connection->getSchemaBuilder()->hasTable($table);

        $swap = function () use ($connection, $grammar, $existed, $table, $temporary, $name, $replaced): void {
            if ($existed) {
                $connection->statement($grammar->compileRenameTable($table, $replaced));
            }

            $connection->statement($grammar->compileRenameTable($temporary, $name));
        };

        $grammar->supportsTransactionalDdl()
            ? $connection->transaction($swap)
            : $swap();

        if ($existed) {
            $connection->statement($grammar->compileDropTable($schema.$replaced));
        }

        return $rows;
    }

    /**
     * Build the new rows beside the target, drop the ones they replace, then insert.
     */
    /**
     * Copy rows from the connection the source lives on onto the model's own. The target
     * table is never created here, so its column types stay the ones you migrated.
     */
    protected function importRows(Node $node): int
    {
        $model = $node->newModel();
        $target = $model->getConnection();
        $table = $model->getTable();

        if (! $target->getSchemaBuilder()->hasTable($table)) {
            throw InvalidImport::missingTable($node->model, $table, $node->connection);
        }

        $key = array_values(array_filter(
            $model->uniqueKey(),
            static fn (string $column): bool => $column !== '',
        ));

        if ($key === []) {
            throw InvalidImport::missingKey($node->model);
        }

        $this->guardUniqueIndex($node, $target, $table, $key);

        if (! $node->appending) {
            $target->table($table)->delete();
        }

        $sql = $node->compiled->sql;
        $bindings = $node->compiled->bindings;
        $appendOnly = $node->appending ? $model->appendOnlyColumn() : null;

        if ($appendOnly !== null) {
            $high = $target->selectOne(sprintf(
                'select max(%s) as high from %s',
                $appendOnly,
                $this->physical($target, $table),
            ))?->high;

            if ($high !== null) {
                $sql = sprintf('select * from (%s) as import_source where %s > ?', $sql, $appendOnly);
                $bindings[] = $high;
            }
        }

        $source = $this->sourceOf($node);
        $size = $model->importChunk();

        return $this->streaming($source, function () use ($source, $sql, $bindings, $target, $table, $key, $size): int {
            $buffer = [];
            $written = 0;

            foreach ($source->cursor($sql, $bindings) as $row) {
                $buffer[] = (array) $row;

                if (count($buffer) < $size) {
                    continue;
                }

                $written += $this->write($target, $table, $buffer, $key);
                $buffer = [];
            }

            return $written + ($buffer === [] ? 0 : $this->write($target, $table, $buffer, $key));
        });
    }

    /**
     * MySQL buffers a whole result set in PHP memory unless told not to, which makes the
     * cost of an import the size of the source rather than the size of a chunk.
     *
     * @param  callable(): int  $read
     */
    protected function streaming(Connection $source, callable $read): int
    {
        if (! in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $read();
        }

        $pdo = $source->getPdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            return $read();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  non-empty-list<non-empty-string>  $key
     */
    protected function write(Connection $target, string $table, array $rows, array $key): int
    {
        $target->table($table)->upsert($rows, $key);

        return count($rows);
    }

    protected function sourceOf(Node $node): Connection
    {
        $source = $node->compiled->sources[0] ?? null;

        /** @var Model $model */
        $model = new $source;

        return $model->getConnection();
    }

    /**
     * An upsert needs somewhere to detect the conflict, and without the index every
     * rerun would insert the same rows again.
     *
     * @param  list<string>  $key
     */
    protected function guardUniqueIndex(Node $node, Connection $target, string $table, array $key): void
    {
        foreach ($target->getSchemaBuilder()->getIndexes($table) as $index) {
            $columns = array_map(strtolower(...), (array) $index['columns']);

            if (($index['unique'] === true || $index['primary'] === true) && $columns === array_map(strtolower(...), $key)) {
                return;
            }
        }

        throw InvalidImport::missingUniqueIndex($node->model, $table, $key);
    }

    protected function appendRows(Node $node): int
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();

        $table = $this->physical($connection, $model->getTable());
        $staging = $table.'__inc_'.Str::lower(Str::random(8));

        $connection->statement(
            $grammar->compileCreateTableAs($staging, $node->compiled->sql),
            $node->compiled->bindings,
        );

        try {
            $rows = (int) $connection->selectOne("select count(*) as aggregate from {$staging}")->aggregate;

            $columns = $this->reconcileColumns($node, $table, $staging);
            $strategy = $model->incrementalStrategy();
            $uniqueKey = $model->uniqueKey();

            if ($strategy === IncrementalStrategy::DeleteInsert && $uniqueKey === []) {
                throw MissingUniqueKey::for($node->model);
            }

            // A reader between the delete and the insert would see the batch missing.
            $connection->transaction(function () use ($connection, $grammar, $strategy, $table, $staging, $uniqueKey, $columns): void {
                if ($strategy === IncrementalStrategy::DeleteInsert) {
                    $connection->statement($grammar->compileDeleteMatching($table, $staging, $uniqueKey));
                }

                $connection->statement($grammar->compileInsertFrom($table, $staging, $columns));
            });
        } finally {
            $connection->statement($grammar->compileDropTable($staging));
        }

        return $rows;
    }

    /**
     * Rebuild one batch at a time, each replacing its own window, so a batch is
     * independent of every other and safe to run again.
     */
    protected function buildBatches(Node $node): int
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();

        $table = $this->physical($connection, $model->getTable());
        $column = (string) $model->eventTime();
        $exists = $connection->getSchemaBuilder()->hasTable($model->getTable());

        $total = 0;

        foreach ($this->batches($node, $model, $exists ? $table : null) as $window) {
            $compiled = $this->compiler->compile($model, false, $window);

            if (! $exists) {
                $connection->statement(
                    $grammar->compileCreateTableAs($table, $compiled->sql),
                    $compiled->bindings,
                );

                $this->createIndexes($node, $table, $table);

                $exists = true;
                $total += (int) $connection->selectOne("select count(*) as aggregate from {$table}")->aggregate;

                continue;
            }

            $staging = $table.'__batch_'.Str::lower(Str::random(8));

            $connection->statement($grammar->compileCreateTableAs($staging, $compiled->sql), $compiled->bindings);

            try {
                $columns = $connection->getSchemaBuilder()->getColumnListing($staging);

                $total += $connection->transaction(function () use ($connection, $grammar, $table, $staging, $columns, $column, $window): int {
                    $connection->statement($grammar->compileDeleteWindow(
                        $table,
                        $column,
                        $window->start->toDateTimeString(),
                        $window->end->toDateTimeString(),
                    ));

                    return $connection->affectingStatement(
                        $grammar->compileInsertFrom($table, $staging, $columns),
                    );
                });
            } finally {
                $connection->statement($grammar->compileDropTable($staging));
            }
        }

        return $total;
    }

    /**
     * Every batch this run should rebuild: the lookback window behind the newest row
     * already stored, or everything since begin() when there is nothing stored yet.
     *
     * @return list<BatchWindow>
     */
    protected function batches(Node $node, Model&AnalyticsModel $model, ?string $table): array
    {
        $size = $model->batchSize();
        $connection = $model->getConnection();

        $end = $size->floor($this->eventTimeEnd ?? Carbon::now())->add($size->interval());

        if ($this->eventTimeStart !== null) {
            $start = $size->floor($this->eventTimeStart);
        } elseif ($table === null) {
            $begin = $model->begin();

            if ($begin === null) {
                throw MissingBatchBegin::for($node->model);
            }

            $start = $size->floor(Carbon::parse($begin));
        } else {
            $newest = $connection->selectOne(
                'select max('.$model->eventTime().') as newest from '.$table,
            )->newest;

            $start = $newest === null
                ? $size->floor(Carbon::parse((string) ($model->begin() ?? Carbon::now())))
                : $size->floor(Carbon::parse((string) $newest));

            for ($step = 0; $step < max(0, $model->lookback()); $step++) {
                $start = $start->sub($size->interval());
            }
        }

        $windows = [];

        while ($start->lessThan($end)) {
            $next = $start->copy()->add($size->interval());
            $windows[] = new BatchWindow($start->copy(), $next);
            $start = $next;
        }

        return $windows;
    }

    /**
     * Close the versions whose tracked columns changed and open one for every row
     * that has none, so the relation keeps a full history of the source.
     */
    protected function snapshotVersions(Node $node): int
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();

        $table = $this->physical($connection, $model->getTable());
        $now = Carbon::now()->toDateTimeString();

        if (! $connection->getSchemaBuilder()->hasTable($model->getTable())) {
            $connection->statement(
                $grammar->compileSnapshotCreate($table, $node->compiled->sql, $now),
                $node->compiled->bindings,
            );

            $this->createIndexes($node, $table, $table);

            return (int) $connection->selectOne("select count(*) as aggregate from {$table}")->aggregate;
        }

        $staging = $table.'__snap_'.Str::lower(Str::random(8));

        $connection->statement(
            $grammar->compileCreateTableAs($staging, $node->compiled->sql),
            $node->compiled->bindings,
        );

        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($staging);
            $uniqueKey = $model->uniqueKey();

            $checkColumns = $model->checkColumns() !== []
                ? $model->checkColumns()
                : array_values(array_diff($columns, $uniqueKey));

            $opened = $connection->transaction(function () use ($connection, $grammar, $table, $staging, $columns, $uniqueKey, $checkColumns, $now): int {
                if ($checkColumns !== []) {
                    $connection->statement($grammar->compileSnapshotClose($table, $staging, $uniqueKey, $checkColumns, $now));
                }

                return $connection->affectingStatement(
                    $grammar->compileSnapshotInsert($table, $staging, $columns, $uniqueKey, $now),
                );
            });
        } finally {
            $connection->statement($grammar->compileDropTable($staging));
        }

        return $opened;
    }

    /**
     * Bring the relation's columns back in line with the model, or refuse to guess.
     *
     * @return list<string>
     */
    protected function reconcileColumns(Node $node, string $table, string $staging): array
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $schema = $connection->getSchemaBuilder();

        $target = $schema->getColumnListing($table);
        $incoming = $schema->getColumnListing($staging);

        $added = array_values(array_diff($incoming, $target));
        $removed = array_values(array_diff($target, $incoming));

        if ($added === [] && $removed === []) {
            return $incoming;
        }

        $strategy = $model->onSchemaChange();

        if ($strategy === SchemaChange::Fail) {
            throw SchemaChanged::between($node->model, $added, $removed);
        }

        if ($strategy === SchemaChange::Ignore) {
            return array_values(array_intersect($incoming, $target));
        }

        $grammar = $model->grammar();
        $types = $this->columnTypes($schema->getColumns($staging));

        foreach ($added as $column) {
            $connection->statement($grammar->compileAddColumn($table, $column, $types[$column] ?? 'text'));
        }

        if ($strategy === SchemaChange::SyncAllColumns) {
            foreach ($removed as $column) {
                $connection->statement($grammar->compileDropColumn($table, $column));
            }

            return $incoming;
        }

        return array_values(array_intersect($incoming, [...$target, ...$added]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, string>
     */
    protected function columnTypes(array $columns): array
    {
        $types = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            $type = $column['type'] ?? $column['type_name'] ?? null;

            if (is_string($name) && is_string($type)) {
                $types[$name] = $type;
            }
        }

        return $types;
    }

    protected function record(Node $node, string $runId, RunStatus $status, ?int $rows, int $durationMs, ?string $error): void
    {
        $connection = $node->newModel()->getConnection();

        if (! $connection->getSchemaBuilder()->hasTable('analytics_runs')) {
            return;
        }

        AnalyticsRun::on($node->connection)->create([
            'run_id' => $runId,
            'model' => $node->model,
            'materialization' => $node->materialization->value,
            'status' => $status,
            'rows' => $rows,
            'duration_ms' => $durationMs,
            'error' => $error,
            'synced_at' => Carbon::now(),
        ]);
    }

    protected function createIndexes(Node $node, string $table, string $prefix): void
    {
        $model = $node->newModel();
        $connection = $model->getConnection();
        $grammar = $model->grammar();

        foreach ($model->indexes() as $position => $columns) {
            $connection->statement($grammar->compileCreateIndex(
                $table,
                $columns,
                $this->unqualified($prefix).'_'.$position,
            ));
        }
    }

    protected function unqualified(string $table): string
    {
        return str_contains($table, '.') ? Str::afterLast($table, '.') : $table;
    }

    protected function physical(Connection $connection, string $table): string
    {
        $schema = str_contains($table, '.') ? Str::beforeLast($table, '.').'.' : '';

        return $schema.$connection->getTablePrefix().Str::afterLast($table, '.');
    }
}
