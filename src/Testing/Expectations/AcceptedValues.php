<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing\Expectations;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class AcceptedValues extends Expectation
{
    /**
     * @param  list<string|int|float>  $values
     */
    public function __construct(protected string $column, protected array $values) {}

    public function describe(): string
    {
        return $this->column.' is one of '.implode(', ', array_map(strval(...), $this->values));
    }

    public function offendingRows(Model&AnalyticsModel $model): string
    {
        $accepted = implode(', ', array_map(
            static fn (string|int|float $value): string => is_string($value)
                ? "'".str_replace("'", "''", $value)."'"
                : (string) $value,
            $this->values,
        ));

        return "select distinct {$this->column} from {$model->getTable()} "
            ."where {$this->column} is not null and {$this->column} not in ({$accepted})";
    }
}
