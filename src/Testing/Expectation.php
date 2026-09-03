<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectations\AcceptedValues;
use Eznix86\LaravelAnalytics\Testing\Expectations\Expression;
use Eznix86\LaravelAnalytics\Testing\Expectations\NotNull;
use Eznix86\LaravelAnalytics\Testing\Expectations\Relationship;
use Eznix86\LaravelAnalytics\Testing\Expectations\Unique;
use Illuminate\Database\Eloquent\Model;

abstract class Expectation
{
    abstract public function describe(): string;

    /**
     * SQL selecting the rows that break this expectation. No rows means it holds.
     */
    abstract public function offendingRows(Model&AnalyticsModel $model): string;

    public static function unique(string ...$columns): self
    {
        return new Unique(array_values($columns));
    }

    public static function notNull(string ...$columns): self
    {
        return new NotNull(array_values($columns));
    }

    /**
     * @param  list<string|int|float>  $values
     */
    public static function acceptedValues(string $column, array $values): self
    {
        return new AcceptedValues($column, $values);
    }

    public static function expression(string $expression): self
    {
        return new Expression($expression);
    }

    /**
     * @param  class-string<Model>  $related
     */
    public static function relationship(string $column, string $related, string $relatedColumn = 'id'): self
    {
        return new Relationship($column, $related, $relatedColumn);
    }
}
