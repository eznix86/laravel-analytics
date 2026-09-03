<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Cycle;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Illuminate\Database\Eloquent\Model;

class Egg extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select * from '.$this->ref(Chicken::class);
    }
}
