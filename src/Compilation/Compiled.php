<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Compilation;

readonly class Compiled
{
    /**
     * @param  list<mixed>  $bindings
     * @param  list<class-string>  $dependencies
     * @param  list<class-string>  $sources
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
        public array $dependencies = [],
        public array $sources = [],
    ) {}
}
