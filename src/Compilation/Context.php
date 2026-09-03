<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Compilation;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Exceptions\CircularDependency;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Query;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

class Context
{
    /**
     * @var array<class-string, true>
     */
    protected array $dependencies = [];

    /**
     * @var array<class-string, true>
     */
    protected array $sources = [];

    /**
     * @var array<string, string>
     */
    protected array $ctes = [];

    /**
     * @var list<mixed>
     */
    protected array $bindings = [];

    /**
     * @var list<class-string>
     */
    protected array $stack = [];

    public function __construct(
        protected bool $fullRefresh = false,
        protected ?BatchWindow $batchWindow = null,
    ) {}

    public function batchWindow(): ?BatchWindow
    {
        return $this->batchWindow;
    }

    public function isFullRefresh(): bool
    {
        return $this->fullRefresh;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public function ref(string $class): string
    {
        $model = new $class;

        if (! $model instanceof AnalyticsModel) {
            $this->sources[$class] = true;

            return $model->getTable();
        }

        $this->dependencies[$class] = true;

        if ($model->materialization() !== Materialization::Ephemeral) {
            return $model->getTable();
        }

        $name = $model->cteName();

        if (array_key_exists($name, $this->ctes)) {
            return $name;
        }

        if (in_array($class, $this->stack, true)) {
            throw CircularDependency::through([...$this->stack, $class]);
        }

        $this->stack[] = $class;

        try {
            $body = $this->render($model);
        } finally {
            array_pop($this->stack);
        }

        $this->ctes[$name] = $body;

        return $name;
    }

    public function render(AnalyticsModel $model): string
    {
        $model->usingCompilationContext($this);

        try {
            $computed = $model->computes();
        } finally {
            $model->usingCompilationContext(null);
        }

        if ($computed instanceof Query) {
            $compiled = $computed->compile($this, $model->grammar());

            foreach ($compiled->bindings as $binding) {
                $this->bindings[] = $binding;
            }

            return trim($compiled->sql);
        }

        if ($computed instanceof Builder) {
            foreach ($computed->getBindings() as $binding) {
                $this->bindings[] = $binding;
            }

            return trim($computed->toSql());
        }

        return trim($computed);
    }

    public function wrap(string $sql): string
    {
        if ($this->ctes === []) {
            return $sql;
        }

        $definitions = [];

        foreach ($this->ctes as $name => $body) {
            $definitions[] = "{$name} as (\n{$body}\n)";
        }

        $recursive = false;

        if (preg_match('/^\s*with\s+(recursive\s+)?/i', $sql, $matches) === 1) {
            $recursive = isset($matches[1]);
            $sql = implode(",\n", $definitions).",\n".substr($sql, strlen($matches[0]));
        } else {
            $sql = implode(",\n", $definitions)."\n".$sql;
        }

        return 'with '.($recursive ? 'recursive ' : '').$sql;
    }

    /**
     * @return list<class-string>
     */
    public function dependencies(): array
    {
        return array_keys($this->dependencies);
    }

    /**
     * @return list<class-string>
     */
    public function sources(): array
    {
        return array_keys($this->sources);
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
