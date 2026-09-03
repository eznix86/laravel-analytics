<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class QueryConfiguration extends RuntimeException
{
    public static function cycle(string $model): self
    {
        return new self(sprintf(
            '%s reads its own analytics configuration from inside computes(). The configuration lives on the query '
            .'computes() returns, so it cannot be read while that query is still being built.',
            class_basename($model),
        ));
    }
}
