<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Compilation;

use Illuminate\Support\Carbon;

/**
 * Half open: rows at exactly `end` belong to the next batch, so consecutive batches
 * neither overlap nor leave a gap.
 */
readonly class BatchWindow
{
    public function __construct(
        public Carbon $start,
        public Carbon $end,
    ) {}
}
