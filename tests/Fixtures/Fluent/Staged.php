<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Eznix86\LaravelAnalytics\ViewQuery;
use Illuminate\Database\Eloquent\Model;

class Staged extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): ViewQuery
    {
        return $this->from(Order::class)
            ->select('id', 'customer_id', 'amount')
            ->view();
    }
}
