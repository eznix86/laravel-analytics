<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class IncrementalWindowFunction extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            "%s is incremental and its SQL contains a window function.\n\n".
            "A window frame reads rows an incremental build never selects, so the first row of every batch gets a wrong value with no error to warn you.\n\n".
            "Fix one of:\n".
            "  - make it a table, so every run recomputes the whole window\n".
            "  - move the window function into a downstream table model that reads this one\n".
            '  - override allowsWindowFunctions() to return true, if you have reasoned about the boundary yourself',
            class_basename($model),
        ));
    }
}
