<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\IncrementalQuery;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Keyed extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): IncrementalQuery
    {
        return $this->from(Order::class)
            ->per(date_trunc('month', 'placed_on')->as('month'))
            ->measure('revenue', 'sum(amount)')
            ->incremental(replacing: ['month'], since: 'month');
    }
}
