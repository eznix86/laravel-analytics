<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

class EphemeralQuery extends Query
{
    public static function materialization(): Materialization
    {
        return Materialization::Ephemeral;
    }
}
