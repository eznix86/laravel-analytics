<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Graph;

use Eznix86\LaravelAnalytics\Compilation\Compiled;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Materialization;
use Illuminate\Database\Eloquent\Model;

readonly class Node
{
    /**
     * @param  class-string<Model&AnalyticsModel>  $model
     */
    public function __construct(
        public string $model,
        public Materialization $materialization,
        public ?string $connection,
        public Compiled $compiled,
        public bool $appending = false,
    ) {}

    public function newModel(): Model&AnalyticsModel
    {
        return new $this->model;
    }

    public function name(): string
    {
        return class_basename($this->model);
    }

    public function isBuildable(): bool
    {
        return $this->materialization !== Materialization::Ephemeral;
    }

    public function label(): string
    {
        return $this->appending
            ? $this->materialization->value.' append'
            : $this->materialization->value;
    }

    /**
     * @return list<class-string>
     */
    public function dependencies(): array
    {
        return $this->compiled->dependencies;
    }
}
