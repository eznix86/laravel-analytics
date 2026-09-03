<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class OutsideCompilation extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            'ref() was called outside of compilation on %s. Call %s::compile() instead of computes() directly, so that dependencies and ephemeral CTEs can be collected.',
            class_basename($model),
            class_basename($model),
        ));
    }
}
