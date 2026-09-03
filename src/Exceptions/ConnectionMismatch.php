<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class ConnectionMismatch extends RuntimeException
{
    public static function between(string $model, ?string $modelConnection, string $dependency, ?string $dependencyConnection): self
    {
        return new self(sprintf(
            "%s (%s) references %s (%s).\n\n".
            "Analytics models cannot query across connections: the database engine cannot join two connections in one statement.\n\n".
            "Fix one of:\n".
            "  - move %s onto the '%s' connection\n".
            '  - import %s onto %s first, with an import model',
            class_basename($model),
            $modelConnection ?? 'default',
            class_basename($dependency),
            $dependencyConnection ?? 'default',
            class_basename($model),
            $dependencyConnection ?? 'default',
            class_basename($dependency),
            $modelConnection ?? 'the default connection',
        ));
    }
}
