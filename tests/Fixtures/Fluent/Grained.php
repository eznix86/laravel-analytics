<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Grained extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->grain(date_trunc('month', 'placed_on'))
            ->measure('first_placed_on', 'min(placed_on)')
            ->measure('orders', 'count(*)');
    }
}
