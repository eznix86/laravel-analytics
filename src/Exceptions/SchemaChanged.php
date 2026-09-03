<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class SchemaChanged extends RuntimeException
{
    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    public static function between(string $model, array $added, array $removed): self
    {
        $changes = [];

        if ($added !== []) {
            $changes[] = 'gained '.implode(', ', $added);
        }

        if ($removed !== []) {
            $changes[] = 'lost '.implode(', ', $removed);
        }

        return new self(sprintf(
            "%s has %s since the relation was built.\n\n".
            "An incremental append cannot reconcile that on its own, so it stops rather than inserting a shape that does not match.\n\n".
            "Fix one of:\n".
            "  - php artisan analytics:sync %s --full-refresh\n".
            '  - return a different SchemaChange from onSchemaChange() to add or sync the columns automatically',
            class_basename($model),
            implode(' and ', $changes),
            class_basename($model),
        ));
    }
}
