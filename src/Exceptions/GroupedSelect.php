<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class GroupedSelect extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'A grouped query cannot use select(), because a selected column that is neither '
            .'grouped nor aggregated is rejected by PostgreSQL and MySQL and silently given an '
            .'arbitrary row by SQLite. Move plain columns to per() and aggregates to measure().',
        );
    }
}
