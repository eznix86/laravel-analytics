<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\EphemeralQuery;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Inlined extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): EphemeralQuery
    {
        return $this->from(Order::class)
            ->select('id', 'customer_id', 'amount')
            ->ephemeral();
    }
}
