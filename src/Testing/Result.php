<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing;

readonly class Result
{
    public function __construct(
        public Expectation $expectation,
        public int $offendingRows,
    ) {}

    public function passed(): bool
    {
        return $this->offendingRows === 0;
    }
}
