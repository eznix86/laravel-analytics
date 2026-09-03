<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Unbuilt;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Unchecked extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select customer_id, amount from '.$this->ref(Order::class);
    }
}
