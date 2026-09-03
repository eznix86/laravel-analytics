<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Resume;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Illuminate\Database\Eloquent\Model;

class Downstream extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select customer_id, label from '.$this->ref(LateArriving::class);
    }
}
