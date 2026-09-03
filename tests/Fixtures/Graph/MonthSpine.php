<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Tests\Fixtures\Graph;

use Eznix86\LaravelAnalytics\Concerns\Analytics;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class MonthSpine extends Model implements AnalyticsModel
{
    use Analytics;

    public function expectations(): array
    {
        return [
            Expectation::unique('month'),
            Expectation::acceptedValues('month', ['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01']),
        ];
    }

    public function computes(): string
    {
        return 'select d as month from '.$this->dateSpine('month', "'2026-01-01'", "'2026-04-01'");
    }
}
