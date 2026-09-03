<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Cte;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Illuminate\Database\Eloquent\Model;

class BigSpenders extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'with totals as (select customer_id, sum(amount) as total from '
            .$this->ref(Filtered::class)
            .' group by customer_id) select customer_id, total from totals where total >= 100';
    }
}
