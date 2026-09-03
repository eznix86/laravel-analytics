<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Contracts;

use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\SchemaChange;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Contracts\Database\Query\Builder;

interface AnalyticsModel
{
    /**
     * The SQL this model is built from.
     */
    public function computes(): string|Builder|Query;

    public function materialization(): Materialization;

    /**
     * @return list<list<string>>
     */
    public function indexes(): array;

    public function freshness(): ?string;

    /**
     * Columns identifying a row, so an incremental build replaces rather than appends.
     *
     * @return list<string>
     */
    public function uniqueKey(): array;

    /**
     * True while compiling a run that appends to an existing incremental relation.
     */
    public function isIncremental(): bool;

    /**
     * How an incremental append folds its new rows into the existing relation.
     */
    public function incrementalStrategy(): IncrementalStrategy;

    /**
     * Columns a snapshot watches for change. Empty watches every non key column.
     *
     * @return list<string>
     */
    public function checkColumns(): array;

    /**
     * The timestamp column a microbatch model slices its batches on.
     */
    public function eventTime(): ?string;

    public function batchSize(): BatchSize;

    /**
     * How many finished batches to rebuild alongside the current one, so rows that
     * arrived late still land.
     */
    public function lookback(): int;

    /**
     * The earliest batch to build when the relation does not exist yet.
     */
    public function begin(): ?string;

    /**
     * Whether this model may use a window function despite being incremental.
     */
    public function allowsWindowFunctions(): bool;

    /**
     * What an incremental append should do when the model's columns no longer match
     * the relation it is appending to.
     */
    public function onSchemaChange(): SchemaChange;

    /**
     * Data expectations checked by analytics:test.
     *
     * @return list<Expectation>
     */
    public function expectations(): array;

    public function compile(): Compiled;

    public function cteName(): string;

    public function grammar(): Grammar;

    public function getTable(): string;

    public function getConnectionName(): ?string;

    /**
     * @internal Used by the compiler to collect dependencies and ephemeral CTEs.
     */
    public function usingCompilationContext(?Context $context): void;
}
