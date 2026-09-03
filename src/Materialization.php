<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

enum Materialization: string
{
    case View = 'view';
    case Table = 'table';
    case Incremental = 'incremental';
    case Microbatch = 'microbatch';
    case Snapshot = 'snapshot';
    case Ephemeral = 'ephemeral';
}
