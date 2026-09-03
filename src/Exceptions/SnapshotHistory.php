<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class SnapshotHistory extends RuntimeException
{
    public static function cannotFullRefresh(string $model): self
    {
        return new self(sprintf(
            "%s is a snapshot, and a full refresh would discard history that cannot be recomputed from the source.\n\n".
            'Sync it without --full-refresh, or drop its relation yourself if you genuinely want to start the history over.',
            class_basename($model),
        ));
    }

    public static function needsUniqueKey(string $model): self
    {
        return new self(sprintf(
            '%s is a snapshot without a unique key, so nothing identifies which row each version belongs to. Return the identifying columns from uniqueKey().',
            class_basename($model),
        ));
    }
}
