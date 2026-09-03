<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Testing\Expectations;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Testing\Expectation;
use Illuminate\Database\Eloquent\Model;

class Relationship extends Expectation
{
    /**
     * @param  class-string<Model>  $related
     */
    public function __construct(
        protected string $column,
        protected string $related,
        protected string $relatedColumn = 'id',
    ) {}

    public function describe(): string
    {
        return $this->column.' exists in '.class_basename($this->related).'.'.$this->relatedColumn;
    }

    public function offendingRows(Model&AnalyticsModel $model): string
    {
        $related = (new $this->related)->getTable();

        return "select child.{$this->column} from {$model->getTable()} child "
            ."left join {$related} parent on parent.{$this->relatedColumn} = child.{$this->column} "
            ."where child.{$this->column} is not null and parent.{$this->relatedColumn} is null";
    }
}
