<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class MissingBatchBegin extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            '%s is a microbatch model with nothing built yet and no begin(), so there is no first batch to start from. Return the earliest date worth building, such as "2026-01-01".',
            class_basename($model),
        ));
    }
}
