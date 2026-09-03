<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Resume;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Depends on a table that only shows up part way through the test, so the first
 * sync fails here and a resume can pick up from it.
 */
class LateArriving extends Model implements AnalyticsModel
{
    use Analytics;

    public function computes(): string
    {
        return 'select o.customer_id, l.label from '.$this->ref(StgOrder::class).' o, late_arriving l';
    }
}
