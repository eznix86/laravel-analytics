<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class UnsupportedDriver extends RuntimeException
{
    /**
     * @param  list<string>  $supported
     */
    public static function for(string $driver, array $supported): self
    {
        sort($supported);

        return new self(sprintf(
            "No analytics grammar is registered for the [%s] driver. Supported drivers are: %s.\n\n".
            'Register one with Analytics::extendGrammar(\'%s\', YourGrammar::class) in a service provider.',
            $driver,
            implode(', ', $supported),
            $driver,
        ));
    }
}
