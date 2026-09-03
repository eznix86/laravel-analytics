<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Graph;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class Revenue extends Model implements AnalyticsModel
{
    use Analytics;

    public function indexes(): array
    {
        return [['customer_id']];
    }

    public function freshness(): ?string
    {
        return '25 hours';
    }

    public function expectations(): array
    {
        return [
            Expectation::unique('customer_id'),
            Expectation::notNull('customer_id', 'total'),
            Expectation::expression('total > 0'),
            Expectation::relationship('customer_id', Order::class, 'customer_id'),
        ];
    }

    public function computes(): string
    {
        return 'select customer_id, total from '.$this->ref(OrderTotals::class).' where total > 0';
    }
}
