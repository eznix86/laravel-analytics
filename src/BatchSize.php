<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;

enum BatchSize: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';

    /**
     * The start of the batch a moment belongs to.
     */
    public function floor(Carbon $moment): Carbon
    {
        return match ($this) {
            self::Hour => $moment->copy()->startOfHour(),
            self::Day => $moment->copy()->startOfDay(),
            self::Month => $moment->copy()->startOfMonth(),
            self::Year => $moment->copy()->startOfYear(),
        };
    }

    public function interval(): CarbonInterval
    {
        return match ($this) {
            self::Hour => CarbonInterval::hour(),
            self::Day => CarbonInterval::day(),
            self::Month => CarbonInterval::month(),
            self::Year => CarbonInterval::year(),
        };
    }
}
