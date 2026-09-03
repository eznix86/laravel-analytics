<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class CircularDependency extends RuntimeException
{
    /**
     * @param  list<class-string>  $cycle
     */
    public static function through(array $cycle): self
    {
        $names = array_map(static fn (string $class): string => class_basename($class), $cycle);

        return new self(sprintf(
            'Analytics models form a dependency cycle: %s. A model cannot reference itself, directly or through another model.',
            implode(' -> ', $names),
        ));
    }

    /**
     * @param  list<class-string>  $unresolved
     */
    public static function among(array $unresolved): self
    {
        $names = array_map(static fn (string $class): string => class_basename($class), $unresolved);
        sort($names);

        return new self(sprintf(
            'Analytics models form a dependency cycle. These models could not be ordered: %s.',
            implode(', ', $names),
        ));
    }
}
