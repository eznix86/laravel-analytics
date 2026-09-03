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

final class Query
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
     * @var list<callable(self): self>
     */
    private array $incrementally = [];

    private ?string $since = null;

    /**
     * @param  class-string<Model>  $model
     */
    private function __construct(
        private readonly string $model,
        private readonly ?string $alias,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function from(string $model, ?string $alias = null): self
    {
        return new self($model, $alias);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function join(string $model, ?string $alias = null, ?string $first = null, ?string $second = null): self
    {
        return $this->addJoin('inner', $model, $alias, $first, $second);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function leftJoin(string $model, ?string $alias = null, ?string $first = null, ?string $second = null): self
    {
        return $this->addJoin('left', $model, $alias, $first, $second);
    }

    /**
     * A second and further condition on the join that was just declared.
     */
    public function on(string $first, string $second): self
    {
        if ($this->joins === []) {
            throw OutsideJoin::for($first, $second);
        }

        return $this->mutate(static function (self $query) use ($first, $second): void {
            $last = count($query->joins) - 1;
            $query->joins[$last]['conditions'][] = [$first, $second];
        });
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
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

    public function whereRaw(string $sql): self
    {
        return $this->mutate(static function (self $query) use ($sql): void {
            $query->wheres[] = $sql;
        });
    }

    public function select(Expression|string ...$columns): self
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
    public function per(Expression|string ...$dimensions): self
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
    public function grain(Expression|string ...$expressions): self
    {
        if ($this->selects !== []) {
            throw GroupedSelect::make();
        }

        return $this->mutate(static function (self $query) use ($expressions): void {
            $query->grains = [...$query->grains, ...array_values($expressions)];
        });
    }

    public function measure(string $alias, Expression|string $expression): self
    {
        return $this->mutate(static function (self $query) use ($alias, $expression): void {
            $query->measures[] = [$alias, $expression];
        });
    }

    /**
     * Applied only on a run that appends to an existing incremental relation, which is
     * the fluent form of dbt's is_incremental() block.
     *
     * @param  callable(self): self  $callback
     */
    public function whenIncremental(callable $callback): self
    {
        return $this->mutate(static function (self $query) use ($callback): void {
            $query->incrementally[] = $callback;
        });
    }

    /**
     * Restrict an incremental run to rows past the high water mark of $column, using the
     * dimension expression behind the column when there is one.
     */
    public function since(string $column): self
    {
        return $this->mutate(static function (self $query) use ($column): void {
            $query->since = $column;
        });
    }

    public function orderBy(string ...$columns): self
    {
        return $this->mutate(static function (self $query) use ($columns): void {
            $query->orders = [...$query->orders, ...array_values($columns)];
        });
    }

    public function limit(int $rows): self
    {
        return $this->mutate(static function (self $query) use ($rows): void {
            $query->limit = $rows;
        });
    }

    /**
     * @param  callable(self): self  $callback
     */
    public function pipe(callable $callback): self
    {
        return $callback($this);
    }

    public function compile(Context $context, Grammar $grammar, AnalyticsModel $model): Compiled
    {
        return $this->resolve($context, $grammar, $model)->render($context, $grammar);
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
     * The query as it applies to this run: incremental blocks folded in, and a microbatch
     * model narrowed to the batch being built.
     */
    private function resolve(Context $context, Grammar $grammar, AnalyticsModel $model): self
    {
        $query = $this;

        if ($model->isIncremental()) {
            foreach ($this->incrementally as $callback) {
                $query = $callback($query);
            }

            if ($this->since !== null) {
                $query = $query->whereRaw($this->watermark($grammar, $model, $this->since));
            }
        }

        $window = $context->batchWindow();
        $eventTime = $model->eventTime();

        if ($window !== null && $eventTime !== null) {
            $query = $query
                ->where($eventTime, '>=', $window->start->toDateTimeString())
                ->where($eventTime, '<', $window->end->toDateTimeString());
        }

        return $query->mutate(static function (self $resolved): void {
            $resolved->incrementally = [];
            $resolved->since = null;
        });
    }

    private function watermark(Grammar $grammar, AnalyticsModel $model, string $column): string
    {
        $operator = $model->incrementalStrategy() === IncrementalStrategy::Append ? '>' : '>=';

        return sprintf(
            '%s %s (select max(%s) from %s)',
            $this->expression($this->dimensionFor($column) ?? $column, $grammar),
            $operator,
            $column,
            $model->getTable(),
        );
    }

    private function dimensionFor(string $alias): ?Expression
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension instanceof Aliased && $dimension->alias() === $alias) {
                return $dimension->expression();
            }
        }

        return null;
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

    private function expression(Expression|string $value, Grammar $grammar): string
    {
        return $value instanceof Expression ? $value->render($grammar) : $value;
    }

    private function isGrouped(): bool
    {
        return $this->dimensions !== [] || $this->grains !== [];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function addJoin(string $type, string $model, ?string $alias, ?string $first, ?string $second): self
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

    /**
     * @param  callable(self): void  $mutate
     */
    private function mutate(callable $mutate): self
    {
        $clone = clone $this;

        $mutate($clone);

        return $clone;
    }
}
