<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing\Expectations;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class Expression extends Expectation
{
    public function __construct(protected string $expression) {}

    public function describe(): string
    {
        return 'every row satisfies '.$this->expression;
    }

    public function offendingRows(Model&AnalyticsModel $model): string
    {
        return "select * from {$model->getTable()} where not ({$this->expression})";
    }
}
