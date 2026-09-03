<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\EphemeralCycle;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Illuminate\Database\Eloquent\Model;

class Yin extends Model implements AnalyticsModel
{
    use Analytics;

    public function materialization(): Materialization
    {
        return Materialization::Ephemeral;
    }

    public function computes(): string
    {
        return 'select * from '.$this->ref(Yang::class);
    }
}
