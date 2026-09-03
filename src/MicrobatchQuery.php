<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

class MicrobatchQuery extends Query
{
    private string $eventTime = '';

    private BatchSize $batchSize = BatchSize::Day;

    private string $begin = '';

    private int $lookback = 1;

    public static function materialization(): Materialization
    {
        return Materialization::Microbatch;
    }

    public function batching(string $eventTime, BatchSize $batchSize, string $begin, int $lookback = 1): static
    {
        return $this->mutate(static function (self $query) use ($eventTime, $batchSize, $begin, $lookback): void {
            $query->eventTime = $eventTime;
            $query->batchSize = $batchSize;
            $query->begin = $begin;
            $query->lookback = $lookback;
        });
    }

    public function eventTime(): string
    {
        return $this->eventTime;
    }

    public function batchSize(): BatchSize
    {
        return $this->batchSize;
    }

    public function begin(): string
    {
        return $this->begin;
    }

    public function lookback(): int
    {
        return $this->lookback;
    }
}
