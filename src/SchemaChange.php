<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

enum SchemaChange: string
{
    /**
     * Stop, because silently dropping or ignoring a column is how wrong numbers ship.
     */
    case Fail = 'fail';

    /**
     * Keep the relation as it is and insert only the columns both sides share.
     */
    case Ignore = 'ignore';

    /**
     * Add columns the model gained. Existing rows keep null for them.
     */
    case AppendNewColumns = 'append_new_columns';

    /**
     * Add columns the model gained and drop the ones it lost.
     */
    case SyncAllColumns = 'sync_all_columns';
}
