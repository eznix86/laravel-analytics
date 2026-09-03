<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Joined extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): Query
    {
        return $this->from(Order::class, 'o')
            ->join(Order::class, 's', 's.id', 'o.id')
            ->on('s.customer_id', 'o.customer_id')
            ->whereRaw("o.status <> 'cancelled'")
            ->select('o.id', 'o.customer_id', 's.amount');
    }
}
