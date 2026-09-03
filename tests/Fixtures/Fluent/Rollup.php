<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Rollup extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->where('status', '<>', 'cancelled')
            ->per(date_trunc('month', 'placed_on')->as('month'), 'customer_id')
            ->measure('revenue', 'sum(amount)')
            ->measure('orders', 'count(*)');
    }
}
