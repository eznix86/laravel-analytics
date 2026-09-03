<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Builder;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BuilderBacked extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string|Builder
    {
        return DB::table($this->ref(Order::class))
            ->select('customer_id', 'amount')
            ->where('status', '<>', 'cancelled');
    }
}
