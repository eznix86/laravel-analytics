<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Mismatch;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Warehoused extends Model implements AnalyticsModel
{
    use Analytics;

    protected $connection = 'warehouse';

    public function computes(): string
    {
        return 'select * from '.$this->ref(Order::class);
    }
}
