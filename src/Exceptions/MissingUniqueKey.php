<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class MissingUniqueKey extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            "%s uses the delete_insert incremental strategy without a unique key, so nothing identifies the rows to replace.\n\n".
            "Fix one of:\n".
            "  - return the columns that identify a row from uniqueKey()\n".
            '  - return IncrementalStrategy::Append from incrementalStrategy(), if rows are never restated',
            class_basename($model),
        ));
    }
}
