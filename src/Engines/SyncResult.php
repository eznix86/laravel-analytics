<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Engines;

use Eznix86\LaravelAnalytics\Graph\Node;

readonly class SyncResult
{
    public function __construct(
        public Node $node,
        public ?int $rows,
        public int $durationMs,
    ) {}
}
