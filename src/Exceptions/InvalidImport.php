<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Exceptions;

use RuntimeException;

class InvalidImport extends RuntimeException
{
    public static function sameConnection(string $model, string $connection): self
    {
        return new self(sprintf(
            "%s imports from a source that is already on the '%s' connection.\n\n".
            'An import copies rows between connections. Use a table model instead.',
            class_basename($model),
            $connection,
        ));
    }

    public static function derivedSource(string $model, string $source): self
    {
        return new self(sprintf(
            "%s imports from %s, which is an analytics model on another connection.\n\n".
            "An import reads a plain Eloquent source, so that the two connections stay independent graphs.\n\n".
            'Import the table %s is built from, or build %s on this connection instead.',
            class_basename($model),
            class_basename($source),
            class_basename($source),
            class_basename($source),
        ));
    }

    public static function missingKey(string $model): self
    {
        return new self(sprintf(
            "%s is an import without a replace key, so a second run would double its rows.\n\n".
            'Give import() the columns that identify a row: import(replacing: [\'id\']).',
            class_basename($model),
        ));
    }

    public static function missingTable(string $model, string $table, string $connection): self
    {
        return new self(sprintf(
            "%s imports into '%s' on the '%s' connection, but that table does not exist.\n\n".
            'An import never creates its target, so that you choose the column types. Write a migration for it.',
            class_basename($model),
            $table,
            $connection,
        ));
    }

    /**
     * @param  list<string>  $key
     */
    public static function missingUniqueIndex(string $model, string $table, array $key): self
    {
        return new self(sprintf(
            "%s imports into '%s' with replace key (%s), but that table has no unique index on those columns.\n\n".
            'Without it every run inserts the rows again. Add $table->unique([%s]) to the migration.',
            class_basename($model),
            $table,
            implode(', ', $key),
            "'".implode("', '", $key)."'",
        ));
    }
}
