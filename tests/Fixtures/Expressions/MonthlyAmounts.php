<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Expressions;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Tests\Fixtures\Graph\Order;
use Illuminate\Database\Eloquent\Model;

use function Eznix86\LaravelAnalytics\cast;
use function Eznix86\LaravelAnalytics\date_trunc;
use function Eznix86\LaravelAnalytics\raw;

class MonthlyAmounts extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select '.$this->render(date_trunc('month', 'placed_on')).' as month, '
            .$this->render(cast(raw('sum(amount)'), 'decimal(18,4)')).' as amount '
            .'from '.$this->ref(Order::class).' group by 1';
    }
}
