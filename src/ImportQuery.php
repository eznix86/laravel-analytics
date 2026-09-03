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

    private ?string $since = null;

    private int $chunk = 1000;

    public static function materialization(): Materialization
    {
        return Materialization::Import;
    }

    /**
     * @param  list<string>  $replacing
     */
    public function copying(array $replacing, ?string $since, int $chunk): static
    {
        return $this->mutate(static function (self $query) use ($replacing, $since, $chunk): void {
            $query->uniqueKey = $replacing;
            $query->since = $since;
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

    public function since(string $column): static
    {
        return $this->mutate(static function (self $query) use ($column): void {
            $query->since = $column;
        });
    }

    public function watermarkColumn(): ?string
    {
        return $this->since;
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
