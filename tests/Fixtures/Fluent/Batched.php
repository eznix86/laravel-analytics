<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\MicrobatchQuery;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Batched extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): MicrobatchQuery
    {
        return $this->from(Order::class)
            ->grain(date_trunc('month', 'placed_on'))
            ->measure('placed_on', 'min(placed_on)')
            ->measure('orders', 'count(*)')
            ->microbatch('placed_on', BatchSize::Month, begin: '2026-01-01');
    }
}
