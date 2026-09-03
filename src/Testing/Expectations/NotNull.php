<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing\Expectations;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class NotNull extends Expectation
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(protected array $columns) {}

    public function describe(): string
    {
        return implode(', ', $this->columns).(count($this->columns) === 1 ? ' is never null' : ' are never null');
    }

    public function offendingRows(Model&AnalyticsModel $model): string
    {
        $conditions = implode(' or ', array_map(
            static fn (string $column): string => "{$column} is null",
            $this->columns,
        ));

        return "select * from {$model->getTable()} where {$conditions}";
    }
}
