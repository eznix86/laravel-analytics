<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Exceptions\GroupedSelect;
use Eznix86\LaravelAnalytics\Exceptions\OutsideJoin;
use Eznix86\LaravelAnalytics\Expressions\Aliased;
use Eznix86\LaravelAnalytics\Expressions\Expression;
use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Query
{
    /**
     * @var list<array{type: string, model: class-string<Model>, alias: ?string, conditions: list<array{string, string}>}>
     */
    private array $joins = [];

    /**
     * @var list<string>
     */
    private array $wheres = [];

    /**
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * @var list<Expression|string>
     */
    private array $selects = [];

    /**
     * @var list<Expression|string>
     */
    private array $dimensions = [];

    /**
     * @var list<Expression|string>
     */
    private array $grains = [];

    /**
     * @var list<array{string, Expression|string}>
     */
    private array $measures = [];

    /**
     * @var list<string>
     */
    private array $orders = [];

    private ?int $limit = null;

    /**
     * @param  class-string<Model>  $model
     */
    protected function __construct(
        protected readonly string $model,
        protected readonly ?string $alias,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function from(string $model, ?string $alias = null): self
    {
        return new self($model, $alias);
    }

    /**
     * How a model returning this query is persisted.
     */
    public static function materialization(): Materialization
    {
        return Materialization::Table;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function join(string $model, ?string $alias = null, ?string $first = null, ?string $second = null): static
    {
        return $this->addJoin('inner', $model, $alias, $first, $second);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function leftJoin(string $model, ?string $alias = null, ?string $first = null, ?string $second = null): static
    {
        return $this->addJoin('left', $model, $alias, $first, $second);
    }

    /**
     * A second and further condition on the join that was just declared.
     */
    public function on(string $first, string $second): static
    {
        if ($this->joins === []) {
            throw OutsideJoin::for($first, $second);
        }

        return $this->mutate(static function (self $query) use ($first, $second): void {
            $last = count($query->joins) - 1;
            $query->joins[$last]['conditions'][] = [$first, $second];
        });
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! is_string($operator)) {
            throw new InvalidArgumentException(sprintf(
                'The operator given to where(%s, ...) must be a string, %s given.',
                $column,
                get_debug_type($operator),
            ));
        }

        return $this->mutate(static function (self $query) use ($column, $operator, $value): void {
            $query->wheres[] = $column.' '.$operator.' ?';
            $query->bindings[] = $value;
        });
    }

    public function whereRaw(string $sql): static
    {
        return $this->mutate(static function (self $query) use ($sql): void {
            $query->wheres[] = $sql;
        });
    }

    public function select(Expression|string ...$columns): static
    {
        if ($this->isGrouped()) {
            throw GroupedSelect::make();
        }

        return $this->mutate(static function (self $query) use ($columns): void {
            $query->selects = [...$query->selects, ...array_values($columns)];
        });
    }

    /**
     * Dimensions, which are selected and grouped by in one statement.
     */
    public function per(Expression|string ...$dimensions): static
    {
        if ($this->selects !== []) {
            throw GroupedSelect::make();
        }

        return $this->mutate(static function (self $query) use ($dimensions): void {
            $query->dimensions = [...$query->dimensions, ...array_values($dimensions)];
        });
    }

    /**
     * A grouping key that is not selected, for a rollup whose output columns differ
     * from the expression it is grouped by.
     */
    public function grain(Expression|string ...$expressions): static
    {
        if ($this->selects !== []) {
            throw GroupedSelect::make();
        }

        return $this->mutate(static function (self $query) use ($expressions): void {
            $query->grains = [...$query->grains, ...array_values($expressions)];
        });
    }

    public function measure(string $alias, Expression|string $expression): static
    {
        return $this->mutate(static function (self $query) use ($alias, $expression): void {
            $query->measures[] = [$alias, $expression];
        });
    }

    public function orderBy(string ...$columns): static
    {
        return $this->mutate(static function (self $query) use ($columns): void {
            $query->orders = [...$query->orders, ...array_values($columns)];
        });
    }

    public function limit(int $rows): static
    {
        return $this->mutate(static function (self $query) use ($rows): void {
            $query->limit = $rows;
        });
    }

    /**
     * @param  callable(static): static  $callback
     */
    public function pipe(callable $callback): static
    {
        return $callback($this);
    }

    /**
     * @param  list<string>  $replacing  Columns identifying a row, so the build replaces rather than appends.
     * @param  string|null  $since  Column whose high water mark bounds an incremental run.
     */
    public function incremental(array $replacing = [], ?string $since = null): IncrementalQuery
    {
        $query = $this->becomes(IncrementalQuery::class)->replacing(...$replacing);

        return $since === null ? $query : $query->since($since);
    }

    public function microbatch(string $eventTime, BatchSize $batchSize, string $begin, int $lookback = 1): MicrobatchQuery
    {
        return $this->becomes(MicrobatchQuery::class)->batching($eventTime, $batchSize, $begin, $lookback);
    }

    /**
     * @param  list<string>  $trackedBy  Columns identifying a row across its versions.
     * @param  list<string>  $whenChanged  Columns watched for change. Empty watches every non key column.
     */
    public function snapshot(array $trackedBy, array $whenChanged = []): SnapshotQuery
    {
        return $this->becomes(SnapshotQuery::class)->tracking($trackedBy, $whenChanged);
    }

    public function view(): ViewQuery
    {
        return $this->becomes(ViewQuery::class);
    }

    public function ephemeral(): EphemeralQuery
    {
        return $this->becomes(EphemeralQuery::class);
    }

    public function compile(Context $context, Grammar $grammar, AnalyticsModel $model): Compiled
    {
        return $this->resolve($context, $grammar, $model)->render($context, $grammar);
    }

    /**
     * Applied only on a run that appends to an existing incremental relation.
     */
    protected function applyIncremental(Grammar $grammar, AnalyticsModel $model): static
    {
        return $this;
    }

    /**
     * @template TQuery of Query
     *
     * @param  class-string<TQuery>  $class
     * @return TQuery
     */
    protected function becomes(string $class): Query
    {
        $query = new $class($this->model, $this->alias);

        $query->joins = $this->joins;
        $query->wheres = $this->wheres;
        $query->bindings = $this->bindings;
        $query->selects = $this->selects;
        $query->dimensions = $this->dimensions;
        $query->grains = $this->grains;
        $query->measures = $this->measures;
        $query->orders = $this->orders;
        $query->limit = $this->limit;

        return $query;
    }

    protected function dimensionFor(string $alias): ?Expression
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension instanceof Aliased && $dimension->alias() === $alias) {
                return $dimension->expression();
            }
        }

        return null;
    }

    protected function expression(Expression|string $value, Grammar $grammar): string
    {
        return $value instanceof Expression ? $value->render($grammar) : $value;
    }

    /**
     * @param  callable(static): void  $mutate
     */
    protected function mutate(callable $mutate): static
    {
        $clone = clone $this;

        $mutate($clone);

        return $clone;
    }

    /**
     * The query as it applies to this run: incremental blocks folded in, and a microbatch
     * model narrowed to the batch being built.
     */
    private function resolve(Context $context, Grammar $grammar, AnalyticsModel $model): static
    {
        $query = $model->isIncremental() ? $this->applyIncremental($grammar, $model) : $this;

        $window = $context->batchWindow();
        $eventTime = $model->eventTime();

        if ($window !== null && $eventTime !== null) {
            $query = $query
                ->where($eventTime, '>=', $window->start->toDateTimeString())
                ->where($eventTime, '<', $window->end->toDateTimeString());
        }

        return $query;
    }

    private function render(Context $context, Grammar $grammar): Compiled
    {
        $sql = 'select '.implode(', ', $this->selectList($grammar))
            .' from '.$this->relation($context, $this->model, $this->alias);

        foreach ($this->joins as $join) {
            $conditions = implode(' and ', array_map(
                static fn (array $condition): string => $condition[0].' = '.$condition[1],
                $join['conditions'],
            ));

            $sql .= ' '.$join['type'].' join '.$this->relation($context, $join['model'], $join['alias'])
                .' on '.$conditions;
        }

        if ($this->wheres !== []) {
            $sql .= ' where '.implode(' and ', $this->wheres);
        }

        $groups = $this->groupList($grammar);

        if ($groups !== []) {
            $sql .= ' group by '.implode(', ', $groups);
        }

        if ($this->orders !== []) {
            $sql .= ' order by '.implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' limit '.$this->limit;
        }

        return new Compiled($sql, $this->bindings);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function relation(Context $context, string $model, ?string $alias): string
    {
        return $context->ref($model).($alias !== null ? ' '.$alias : '');
    }

    /**
     * @return list<string>
     */
    private function selectList(Grammar $grammar): array
    {
        $list = [];

        foreach ($this->dimensions as $dimension) {
            $list[] = $this->expression($dimension, $grammar);
        }

        foreach ($this->measures as [$alias, $expression]) {
            $list[] = $this->expression($expression, $grammar).' as '.$alias;
        }

        foreach ($this->selects as $select) {
            $list[] = $this->expression($select, $grammar);
        }

        return $list === [] ? ['*'] : $list;
    }

    /**
     * @return list<string>
     */
    private function groupList(Grammar $grammar): array
    {
        $list = [];

        foreach ($this->dimensions as $dimension) {
            $list[] = $this->expression(
                $dimension instanceof Aliased ? $dimension->expression() : $dimension,
                $grammar,
            );
        }

        foreach ($this->grains as $grain) {
            $list[] = $this->expression($grain, $grammar);
        }

        return $list;
    }

    private function isGrouped(): bool
    {
        return $this->dimensions !== [] || $this->grains !== [];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function addJoin(string $type, string $model, ?string $alias, ?string $first, ?string $second): static
    {
        return $this->mutate(static function (self $query) use ($type, $model, $alias, $first, $second): void {
            $query->joins[] = [
                'type' => $type,
                'model' => $model,
                'alias' => $alias,
                'conditions' => $first !== null && $second !== null ? [[$first, $second]] : [],
            ];
        });
    }
}
