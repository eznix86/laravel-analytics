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

        $results = [];

        foreach ($model->expectations() as $expectation) {
            $sql = $expectation->offendingRows($model);

            $offending = $model->getConnection()
                ->selectOne("select count(*) as aggregate from ({$sql}) as offending");

            $results[] = new Result($expectation, (int) $offending->aggregate);
        }

        return $results;
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
