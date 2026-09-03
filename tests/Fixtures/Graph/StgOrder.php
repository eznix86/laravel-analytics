<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Graph;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class StgOrder extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::View;
    }

    public function expectations(): array
    {
        return [Expectation::notNull('id', 'customer_id')];
    }

    public function computes(): string
    {
        return 'select id, customer_id, amount from '.$this->ref(Order::class)." where status <> 'cancelled'";
    }
}
