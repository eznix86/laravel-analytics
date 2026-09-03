<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

use Eznix86\LaravelAnalytics\Expressions\Cast;
use Eznix86\LaravelAnalytics\Expressions\DateAdd;
use Eznix86\LaravelAnalytics\Expressions\DateDiff;
use Eznix86\LaravelAnalytics\Expressions\DateSpine;
use Eznix86\LaravelAnalytics\Expressions\DateTrunc;
use Eznix86\LaravelAnalytics\Expressions\Expression;
use Eznix86\LaravelAnalytics\Expressions\Raw;
use Eznix86\LaravelAnalytics\Expressions\StringAgg;

function raw(string $sql, Expression|string ...$operands): Raw
{
    return new Raw($sql, ...$operands);
}

function date_trunc(string $unit, Expression|string $column): DateTrunc
{
    return new DateTrunc($unit, $column);
}

function date_add(string $unit, int $amount, Expression|string $column): DateAdd
{
    return new DateAdd($unit, $amount, $column);
}

function date_diff(string $unit, Expression|string $start, Expression|string $end): DateDiff
{
    return new DateDiff($unit, $start, $end);
}

function date_spine(string $unit, Expression|string $start, Expression|string $end, string $as = 'spine'): DateSpine
{
    return new DateSpine($unit, $start, $end, $as);
}

function string_agg(Expression|string $column, string $delimiter = ','): StringAgg
{
    return new StringAgg($column, $delimiter);
}

function cast(Expression|string $expression, string $type): Cast
{
    return new Cast($expression, $type);
}
