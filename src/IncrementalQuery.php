<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Grammars\Grammar;

class IncrementalQuery extends Query
{
    /**
     * @var list<string>
     */
    private array $uniqueKey = [];

    private ?string $since = null;

    /**
     * @var list<callable(static): static>
     */
    private array $incrementally = [];

    public static function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function replacing(string ...$columns): static
    {
        return $this->mutate(static function (self $query) use ($columns): void {
            $query->uniqueKey = array_values($columns);
        });
    }

    /**
     * Restrict an incremental run to rows past the high water mark of $column, using the
     * dimension expression behind the column when there is one.
     */
    public function since(string $column): static
    {
        return $this->mutate(static function (self $query) use ($column): void {
            $query->since = $column;
        });
    }

    /**
     * Applied only on a run that appends to an existing relation, which is the fluent
     * form of dbt's is_incremental() block.
     *
     * @param  callable(static): static  $callback
     */
    public function whenIncremental(callable $callback): static
    {
        return $this->mutate(static function (self $query) use ($callback): void {
            $query->incrementally[] = $callback;
        });
    }

    /**
     * @return list<string>
     */
    public function uniqueKey(): array
    {
        return $this->uniqueKey;
    }

    protected function applyIncremental(Grammar $grammar, AnalyticsModel $model): static
    {
        $query = $this;

        foreach ($this->incrementally as $callback) {
            $query = $callback($query);
        }

        if ($this->since === null) {
            return $query;
        }

        return $query->whereRaw($this->watermark($grammar, $model, $this->since));
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
}
