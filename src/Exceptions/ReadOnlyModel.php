<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class ReadOnlyModel extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            '%s is an analytics model. It is rebuilt from scratch by analytics:sync, so writes would be discarded on the next run.',
            class_basename($model),
        ));
    }
}
