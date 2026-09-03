<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Concerns;

use BackedEnum;
use Carbon\CarbonInterval;
use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Exceptions\NotQueryable;
use Eznix86\LaravelAnalytics\Exceptions\OutsideBatch;
use Eznix86\LaravelAnalytics\Exceptions\OutsideCompilation;
use Eznix86\LaravelAnalytics\Exceptions\QueryConfiguration;
use Eznix86\LaravelAnalytics\Exceptions\ReadOnlyModel;
use Eznix86\LaravelAnalytics\Expressions\Expression;
use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Eznix86\LaravelAnalytics\IncrementalQuery;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\MicrobatchQuery;
use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\RunStatus;
use Eznix86\LaravelAnalytics\SchemaChange;
use Eznix86\LaravelAnalytics\SnapshotQuery;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

trait Analytics
{
    protected ?Context $analyticsContext = null;

    protected ?Query $analyticsConfiguration = null;

    protected bool $readingAnalyticsConfiguration = false;

    /**
     * @var array<class-string, class-string<Query>|null>
     */
    protected static array $analyticsQueryClasses = [];

    /**
     * The SQL this model is built from.
     */
    abstract public function computes(): string|Builder|Query;

    public function materialization(): Materialization
    {
        $query = $this->declaredQueryClass();

        return $query === null ? Materialization::Table : $query::materialization();
    }

    /**
     * Indexes rebuilt on every sync, because `create table as select` produces none.
     *
     * @return list<list<string>>
     */
    public function indexes(): array
    {
        return [];
    }

    /**
     * How old this model may be before `isStale()` reports true, e.g. "25 hours".
     */
    public function freshness(): ?string
    {
        return null;
    }

    /**
     * Data expectations checked by analytics:test.
     *
     * @return list<Expectation>
     */
    public function expectations(): array
    {
        return [];
    }

    /**
     * Columns identifying a row, so an incremental build replaces rather than appends.
     *
     * @return list<string>
     */
    public function uniqueKey(): array
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof IncrementalQuery || $query instanceof SnapshotQuery
            ? $query->uniqueKey()
            : [];
    }

    /**
     * False on the first build, under --full-refresh, and for every other materialization,
     * so `computes()` can add its own filter only when there is something to append to.
     */
    public function isIncremental(): bool
    {
        if ($this->materialization() !== Materialization::Incremental) {
            return false;
        }

        if ($this->analyticsContext?->isFullRefresh() === true) {
            return false;
        }

        return $this->getConnection()->getSchemaBuilder()->hasTable($this->getTable());
    }

    /**
     * Declaring a unique key is what says rows can be restated, so it picks the strategy.
     */
    public function incrementalStrategy(): IncrementalStrategy
    {
        return $this->uniqueKey() === []
            ? IncrementalStrategy::Append
            : IncrementalStrategy::DeleteInsert;
    }

    /**
     * Columns a snapshot watches for change. Empty watches every non key column.
     *
     * @return list<string>
     */
    public function checkColumns(): array
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof SnapshotQuery ? $query->checkColumns() : [];
    }

    public function eventTime(): ?string
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof MicrobatchQuery ? $query->eventTime() : null;
    }

    public function batchSize(): BatchSize
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof MicrobatchQuery ? $query->batchSize() : BatchSize::Day;
    }

    public function lookback(): int
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof MicrobatchQuery ? $query->lookback() : 1;
    }

    public function begin(): ?string
    {
        $query = $this->analyticsConfiguration();

        return $query instanceof MicrobatchQuery ? $query->begin() : null;
    }

    /**
     * The predicate restricting a microbatch model to the batch being built. Place it
     * in your own where clause rather than having it spliced into your SQL.
     */
    protected function batchWindow(): string
    {
        $window = $this->analyticsContext?->batchWindow();

        if ($window === null) {
            throw OutsideBatch::for(static::class);
        }

        $column = $this->eventTime();

        if ($column === null) {
            throw OutsideBatch::needsEventTime(static::class);
        }

        return sprintf(
            "%s >= '%s' and %s < '%s'",
            $column,
            $window->start->toDateTimeString(),
            $column,
            $window->end->toDateTimeString(),
        );
    }

    public function allowsWindowFunctions(): bool
    {
        return false;
    }

    public function onSchemaChange(): SchemaChange
    {
        return SchemaChange::Fail;
    }

    public function compile(): Compiled
    {
        return app(Compiler::class)->compile($this);
    }

    public function cteName(): string
    {
        return 'cte_'.Str::snake(class_basename($this));
    }

    public function grammar(): Grammar
    {
        return app(GrammarManager::class)->for($this->getConnection()->getDriverName());
    }

    public function getTable(): string
    {
        if ($this->materialization() === Materialization::Ephemeral) {
            throw NotQueryable::ephemeral(static::class);
        }

        if (isset($this->table)) {
            return $this->table;
        }

        $name = Str::snake(class_basename($this));
        $schema = config('analytics.schema');

        return is_string($schema) && $schema !== ''
            ? $schema.'.'.$name
            : config('analytics.prefix', 'analytics_').$name;
    }

    public function getConnectionName(): ?string
    {
        $connection = $this->connection;

        if ($connection instanceof BackedEnum) {
            $connection = $connection->value;
        } elseif ($connection instanceof UnitEnum) {
            $connection = $connection->name;
        }

        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        $configured = config('analytics.connection');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    public function usesTimestamps(): bool
    {
        return false;
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public static function lastSyncedAt(): ?Carbon
    {
        $run = AnalyticsRun::on(app(static::class)->getConnectionName())
            ->where('model', static::class)
            ->where('status', RunStatus::Success)
            ->orderByDesc('synced_at')
            ->first();

        return $run?->synced_at;
    }

    public static function isStale(): bool
    {
        $freshness = app(static::class)->freshness();

        if ($freshness === null) {
            return false;
        }

        $syncedAt = static::lastSyncedAt();

        if ($syncedAt === null) {
            return true;
        }

        return $syncedAt->lessThan(Carbon::now()->sub(CarbonInterval::make($freshness)));
    }

    /**
     * @internal Used by the compiler to collect dependencies and ephemeral CTEs.
     */
    public function usingCompilationContext(?Context $context): void
    {
        $this->analyticsContext = $context;
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function from(string $model, ?string $alias = null): Query
    {
        return Query::from($model, $alias);
    }

    /**
     * The declared return type of computes() is what says how a fluent model is persisted,
     * and whether calling computes() to read its configuration is safe at all: a model
     * returning raw SQL may depend on a compilation state that only a real build has.
     *
     * @return class-string<Query>|null
     */
    protected function declaredQueryClass(): ?string
    {
        if (array_key_exists(static::class, static::$analyticsQueryClasses)) {
            return static::$analyticsQueryClasses[static::class];
        }

        $type = (new ReflectionMethod(static::class, 'computes'))->getReturnType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        return static::$analyticsQueryClasses[static::class] = $name === Query::class || is_subclass_of($name, Query::class)
            ? $name
            : null;
    }

    /**
     * The query computes() returns, which is where a fluent model keeps the configuration
     * the engine would otherwise read from an overridden method.
     */
    protected function analyticsConfiguration(): ?Query
    {
        if ($this->analyticsConfiguration !== null) {
            return $this->analyticsConfiguration;
        }

        if ($this->declaredQueryClass() === null) {
            return null;
        }

        if ($this->readingAnalyticsConfiguration) {
            throw QueryConfiguration::cycle(static::class);
        }

        $previous = $this->analyticsContext;
        $this->readingAnalyticsConfiguration = true;
        $this->usingCompilationContext($previous ?? new Context);

        try {
            $computed = $this->computes();
        } finally {
            $this->usingCompilationContext($previous);
            $this->readingAnalyticsConfiguration = false;
        }

        return $this->analyticsConfiguration = Query::fromComputed($computed);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function ref(string $model): string
    {
        if ($this->analyticsContext === null) {
            throw OutsideCompilation::for(static::class);
        }

        return $this->analyticsContext->ref($model);
    }

    protected function dateTrunc(string $unit, string $column): string
    {
        return $this->grammar()->dateTrunc($unit, $column);
    }

    protected function dateAdd(string $unit, int $amount, string $column): string
    {
        return $this->grammar()->dateAdd($unit, $amount, $column);
    }

    protected function dateDiff(string $unit, string $start, string $end): string
    {
        return $this->grammar()->dateDiff($unit, $start, $end);
    }

    protected function dateSpine(string $unit, string $start, string $end, string $as = 'spine'): string
    {
        return $this->grammar()->dateSpine($unit, $start, $end, $as);
    }

    protected function stringAgg(string $column, string $delimiter = ','): string
    {
        return $this->grammar()->stringAgg($column, $delimiter);
    }

    protected function castAs(string $expression, string $type): string
    {
        return $this->grammar()->cast($expression, $type);
    }

    protected function render(Expression|string $expression): string
    {
        return $expression instanceof Expression
            ? $expression->render($this->grammar())
            : $expression;
    }

    protected function performInsert(EloquentBuilder $query): bool
    {
        throw ReadOnlyModel::for(static::class);
    }

    protected function performUpdate(EloquentBuilder $query): bool
    {
        throw ReadOnlyModel::for(static::class);
    }

    protected function performDeleteOnModel(): void
    {
        throw ReadOnlyModel::for(static::class);
    }
}
