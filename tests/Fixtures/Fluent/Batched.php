<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\BatchSize;
use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Batched extends Model implements AnalyticsModel
{
    use Analytics;

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
        return BatchSize::Month;
    }

    public function begin(): ?string
    {
        return '2026-01-01';
    }

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->grain(date_trunc('month', 'placed_on'))
            ->measure('placed_on', 'min(placed_on)')
            ->measure('orders', 'count(*)');
    }
}
