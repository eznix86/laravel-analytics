<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

enum IncrementalStrategy: string
{
    /**
     * Insert the new rows and nothing else. Right for an immutable log.
     */
    case Append = 'append';

    /**
     * Delete the rows the new batch restates, then insert it. Needs a unique key.
     */
    case DeleteInsert = 'delete_insert';
}
