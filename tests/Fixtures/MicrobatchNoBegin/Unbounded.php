<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\MicrobatchNoBegin;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Unbounded extends Model implements AnalyticsModel
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

    public function computes(): string
    {
        return 'select min(placed_on) as placed_on, count(*) as orders from '.$this->ref(Order::class)
            .' where '.$this->batchWindow().' group by '.$this->dateTrunc('day', 'placed_on');
    }
}
