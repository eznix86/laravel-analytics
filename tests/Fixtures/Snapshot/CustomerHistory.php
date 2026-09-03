<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Snapshot;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class CustomerHistory extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Snapshot;
    }

    public function uniqueKey(): array
    {
        return ['customer_id'];
    }

    public function indexes(): array
    {
        return [['customer_id']];
    }

    public function computes(): string
    {
        return 'select customer_id, sum(amount) as total, count(*) as orders from '
            .$this->ref(Order::class).' group by customer_id';
    }
}
