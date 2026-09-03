<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\IncrementalWindowAllowed;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Reviewed extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function allowsWindowFunctions(): bool
    {
        return true;
    }

    public function computes(): string
    {
        return 'select id, customer_id, sum(amount) over (partition by customer_id order by id) as running '
            .'from '.$this->ref(Order::class);
    }
}
