<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Microbatch;

use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class DailyOrders extends Model implements AnalyticsModel
{
    use Analytics;

    public static string $begin = '2026-01-01';

    public function materialization(): Materialization
    {
        return Materialization::Microbatch;
    }

    public function eventTime(): ?string
    {
        return 'placed_on';
    }

    public function batchSize(): BatchSize
    {
        return BatchSize::Day;
    }

    public function begin(): ?string
    {
        return static::$begin;
    }

    public function indexes(): array
    {
        return [['placed_on']];
    }

    public function computes(): string
    {
        return 'select min(placed_on) as placed_on, count(*) as orders, sum(amount) as revenue from '
            .$this->ref(Order::class)
            .' where '.$this->batchWindow()
            .' group by '.$this->dateTrunc('day', 'placed_on');
    }
}
