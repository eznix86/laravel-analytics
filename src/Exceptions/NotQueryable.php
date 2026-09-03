<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class NotQueryable extends RuntimeException
{
    public static function ephemeral(string $model): self
    {
        return new self(sprintf(
            '%s is an ephemeral analytics model, so it has no table or view to query. It is inlined as a CTE into the models that reference it. Change its materialization to table or view to query it directly.',
            class_basename($model),
        ));
    }
}
