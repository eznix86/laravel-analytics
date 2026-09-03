<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\StrategyAppend;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * Has a unique key for its expectations, but is an immutable log, so it appends.
 */
class Logged extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function uniqueKey(): array
    {
        return ['id'];
    }

    public function incrementalStrategy(): IncrementalStrategy
    {
        return IncrementalStrategy::Append;
    }

    public function computes(): string
    {
        $sql = 'select id, customer_id from '.$this->ref(Order::class);

        if ($this->isIncremental()) {
            $sql .= ' where id > (select max(id) from '.$this->getTable().')';
        }

        return $sql;
    }
}
