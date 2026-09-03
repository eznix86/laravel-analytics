<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

class SnapshotQuery extends Query
{
    /**
     * @var list<string>
     */
    private array $trackedBy = [];

    /**
     * @var list<string>
     */
    private array $whenChanged = [];

    public static function materialization(): Materialization
    {
        return Materialization::Snapshot;
    }

    /**
     * @param  list<string>  $trackedBy
     * @param  list<string>  $whenChanged
     */
    public function tracking(array $trackedBy, array $whenChanged = []): static
    {
        return $this->mutate(static function (self $query) use ($trackedBy, $whenChanged): void {
            $query->trackedBy = $trackedBy;
            $query->whenChanged = $whenChanged;
        });
    }

    /**
     * @return list<string>
     */
    public function uniqueKey(): array
    {
        return $this->trackedBy;
    }

    /**
     * @return list<string>
     */
    public function checkColumns(): array
    {
        return $this->whenChanged;
    }
}
