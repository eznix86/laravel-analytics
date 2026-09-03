<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

class ViewQuery extends Query
{
    public static function materialization(): Materialization
    {
        return Materialization::View;
    }
}
