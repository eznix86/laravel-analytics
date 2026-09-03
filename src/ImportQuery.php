<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Compilation\Context;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Grammars\Grammar;
use Eznix86\LaravelAnalytics\Grammars\GrammarManager;
use Illuminate\Database\Eloquent\Model;

class ImportQuery extends Query
{
    /**
     * @var list<string>
     */
    private array $uniqueKey = [];

    private ?string $appendOnly = null;

    private int $chunk = 1000;

    public static function materialization(): Materialization
    {
        return Materialization::Import;
    }

    /**
     * @param  list<string>  $replacing
     */
    public function copying(array $replacing, ?string $appendOnly, int $chunk): static
    {
        return $this->mutate(static function (self $query) use ($replacing, $appendOnly, $chunk): void {
            $query->uniqueKey = $replacing;
            $query->appendOnly = $appendOnly;
            $query->chunk = $chunk;
        });
    }

    /**
     * @return list<string>
     */
    public function uniqueKey(): array
    {
        return $this->uniqueKey;
    }

    /**
     * Read only past the highest value already stored. Correct only while the source
     * never deletes or restates a row, which the package cannot check for you.
     */
    public function appendOnly(string $column): static
    {
        return $this->mutate(static function (self $query) use ($column): void {
            $query->appendOnly = $column;
        });
    }

    public function appendOnlyColumn(): ?string
    {
        return $this->appendOnly;
    }

    public function chunkSize(): int
    {
        return $this->chunk;
    }

    /**
     * The class the rows are read from, which lives on the connection being imported from.
     *
     * @return class-string<Model>
     */
    public function source(): string
    {
        return $this->model;
    }

    /**
     * Compiled against the source connection, because that is where the select runs.
     */
    public function compile(Context $context, Grammar $grammar, AnalyticsModel $model): Compiled
    {
        return parent::compile($context, $this->sourceGrammar(), $model);
    }

    private function sourceGrammar(): Grammar
    {
        $source = $this->model;

        return app(GrammarManager::class)->for((new $source)->getConnection()->getDriverName());
    }
}
