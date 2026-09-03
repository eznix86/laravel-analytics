<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Incremental;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

class Events extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Incremental;
    }

    public function computes(): string
    {
        $sql = 'select id, customer_id, amount from '.$this->ref(Order::class);

        if ($this->isIncremental()) {
            $sql .= ' where id > (select max(id) from '.$this->getTable().')';
        }

        return $sql;
    }
}
