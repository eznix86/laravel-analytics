<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class OutsideBatch extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            'batchWindow() was called on %s outside of a microbatch build. Only a model whose materialization is Microbatch has a batch to filter by.',
            class_basename($model),
        ));
    }

    public static function needsEventTime(string $model): self
    {
        return new self(sprintf(
            '%s is a microbatch model without an event time column, so there is nothing to slice batches on. Return the timestamp column from eventTime().',
            class_basename($model),
        ));
    }
}
