<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Exceptions\NotQueryable;
use Eznix86\LaravelAnalytics\Materialization;
use Illuminate\Database\Eloquent\Model;

class Runner
{
    /**
     * @return list<Result>
     */
    public function run(Model&AnalyticsModel $model): array
    {
        if ($model->materialization() === Materialization::Ephemeral) {
            throw NotQueryable::ephemeral($model::class);
        }

        $expectations = $model->expectations();

        if ($expectations === []) {
            return [];
        }

        if (! $this->exists($model)) {
            throw NotQueryable::notBuilt($model::class);
        }

        $results = [];

        foreach ($expectations as $expectation) {
            $sql = $expectation->offendingRows($model);

            $offending = $model->getConnection()
                ->selectOne("select count(*) as aggregate from ({$sql}) as offending");

            $results[] = new Result($expectation, (int) $offending->aggregate);
        }

        return $results;
    }

    /**
     * A view is not a table on every driver, so both are asked for.
     */
    protected function exists(Model&AnalyticsModel $model): bool
    {
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        return $schema->hasTable($table) || $schema->hasView($table);
    }

    /**
     * @return list<Result>
     */
    public function failures(Model&AnalyticsModel $model): array
    {
        return array_values(array_filter(
            $this->run($model),
            static fn (Result $result): bool => ! $result->passed(),
        ));
    }
}
