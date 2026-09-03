<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\StrategyBroken;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\IncrementalStrategy;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Keyless extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function incrementalStrategy(): IncrementalStrategy
    {
        return IncrementalStrategy::DeleteInsert;
    }

    public function computes(): string
    {
        return 'select id, customer_id from '.$this->ref(Order::class);
    }
}
