<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class OutsideJoin extends RuntimeException
{
    public static function for(string $first, string $second): self
    {
        return new self(sprintf(
            'on(%s, %s) has no join to attach to. Call join() first; on() only adds a further condition to it.',
            $first,
            $second,
        ));
    }
}
