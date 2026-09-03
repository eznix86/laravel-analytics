<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing\Expectations;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class Unique extends Expectation
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(protected array $columns) {}

    public function describe(): string
    {
        return implode(', ', $this->columns).(count($this->columns) === 1 ? ' is unique' : ' are unique together');
    }

    public function offendingRows(Model&AnalyticsModel $model): string
    {
        $columns = implode(', ', $this->columns);

        return "select {$columns} from {$model->getTable()} group by {$columns} having count(*) > 1";
    }
}
