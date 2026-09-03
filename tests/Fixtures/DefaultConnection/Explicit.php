<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\DefaultConnection;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Explicit extends Model implements AnalyticsModel
{
    use Analytics;

    protected $connection = 'testing';

    public function computes(): string
    {
        return 'select id, amount from '.$this->ref(Order::class);
    }
}
