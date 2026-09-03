<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\SnapshotQuery;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Versioned extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): SnapshotQuery
    {
        return $this->from(Order::class)
            ->select('id', 'customer_id', 'amount')
            ->snapshot(trackedBy: ['id'], whenChanged: ['amount']);
    }
}
