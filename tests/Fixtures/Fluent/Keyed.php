<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Fluent;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Query;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\date_trunc;

class Keyed extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function uniqueKey(): array
    {
        return ['month'];
    }

    public function computes(): Query
    {
        return $this->from(Order::class)
            ->per(date_trunc('month', 'placed_on')->as('month'))
            ->measure('revenue', 'sum(amount)')
            ->since('month');
    }
}
