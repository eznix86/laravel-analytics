<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Graph;

use Eznix86\LaravelAnalytics\Compilation\BatchWindow;
use Eznix86\LaravelAnalytics\Compilation\Compiler;
use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;
use Eznix86\LaravelAnalytics\Exceptions\CircularDependency;
use Eznix86\LaravelAnalytics\Exceptions\ConnectionMismatch;
use Eznix86\LaravelAnalytics\Exceptions\IncrementalWindowFunction;
use Eznix86\LaravelAnalytics\Exceptions\OutsideBatch;
use Eznix86\LaravelAnalytics\Exceptions\SnapshotHistory;
use Eznix86\LaravelAnalytics\Materialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\Finder\Finder;

class Resolver
{
    public function __construct(protected Compiler $compiler) {}

    /**
     * Analytics model classes found under the configured path.
     *
     * @return list<class-string<Model&AnalyticsModel>>
     */
    public function discover(): array
    {
        $path = config('analytics.path');
        $namespace = trim((string) config('analytics.namespace'), '\\');

        if (! is_string($path) || ! is_dir($path)) {
            return [];
        }

        $root = (string) realpath($path);
        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($root) as $file) {
            $relative = trim(str_replace($root, '', (string) $file->getRealPath()), DIRECTORY_SEPARATOR);
            $class = $namespace.'\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, AnalyticsModel::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Every discovered model, compiled, validated and sorted into build order.
     *
     * @return list<Node>
     */
    public function resolve(?string $connection = null, bool $fullRefresh = false): array
    {
        return array_merge(...[[], ...$this->levels($connection, $fullRefresh)]);
    }

    /**
     * Build order grouped into waves. Everything inside one wave depends only on
     * earlier waves, so a wave can be built concurrently.
     *
     * @return list<list<Node>>
     */
    public function levels(?string $connection = null, bool $fullRefresh = false): array
    {
        $nodes = [];

        foreach ($this->discover() as $class) {
            $model = new $class;

            $appending = $model->materialization() === Materialization::Incremental
                && ! $fullRefresh
                && $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());

            $nodes[$class] = new Node(
                $class,
                $model->materialization(),
                $this->connectionOf($model),
                $this->compiler->compile($model, $fullRefresh, $this->currentWindow($model)),
                $appending,
            );
        }

        $this->guardConnections($nodes);
        $this->guardIncrementalWindows($nodes);
        $this->guardSnapshots($nodes);

        $levels = $this->sort($nodes);

        if ($connection === null) {
            return $levels;
        }

        $filtered = [];

        foreach ($levels as $level) {
            $matching = array_values(array_filter(
                $level,
                static fn (Node $node): bool => $node->connection === $connection,
            ));

            if ($matching !== []) {
                $filtered[] = $matching;
            }
        }

        return $filtered;
    }

    /**
     * A model without an explicit connection uses the application default, so both
     * spellings have to resolve to the same name before any comparison.
     */
    protected function connectionOf(Model $model): string
    {
        return $model->getConnectionName() ?? (string) config('database.default');
    }

    /**
     * @param  array<class-string, Node>  $nodes
     */
    protected function guardConnections(array $nodes): void
    {
        foreach ($nodes as $node) {
            foreach ($node->dependencies() as $dependency) {
                if (! isset($nodes[$dependency])) {
                    continue;
                }

                if ($nodes[$dependency]->connection !== $node->connection) {
                    throw ConnectionMismatch::between(
                        $node->model,
                        $node->connection,
                        $dependency,
                        $nodes[$dependency]->connection,
                    );
                }
            }

            foreach ($node->compiled->sources as $source) {
                /** @var Model $sourceModel */
                $sourceModel = new $source;
                $sourceConnection = $this->connectionOf($sourceModel);

                if ($sourceConnection !== $node->connection) {
                    throw ConnectionMismatch::between(
                        $node->model,
                        $node->connection,
                        $source,
                        $sourceConnection,
                    );
                }
            }
        }
    }

    /**
     * A window frame reads rows an incremental build never selects, so the boundary row
     * silently gets the wrong value. Refuse it unless the model opts in.
     *
     * @param  array<class-string, Node>  $nodes
     */
    protected function guardIncrementalWindows(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! in_array($node->materialization, [Materialization::Incremental, Materialization::Microbatch], true)) {
                continue;
            }

            if ($node->newModel()->allowsWindowFunctions()) {
                continue;
            }

            if (preg_match('/\bover\s*\(/i', $node->compiled->sql) === 1) {
                throw IncrementalWindowFunction::for($node->model);
            }
        }
    }

    /**
     * Compiling needs some window, so use the batch that is open right now. The engine
     * recompiles per batch when it actually builds.
     */
    protected function currentWindow(Model&AnalyticsModel $model): ?BatchWindow
    {
        if ($model->materialization() !== Materialization::Microbatch) {
            return null;
        }

        if ($model->eventTime() === null) {
            throw OutsideBatch::needsEventTime($model::class);
        }

        $start = $model->batchSize()->floor(Carbon::now());

        return new BatchWindow($start, $start->copy()->add($model->batchSize()->interval()));
    }

    /**
     * @param  array<class-string, Node>  $nodes
     */
    protected function guardSnapshots(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node->materialization !== Materialization::Snapshot) {
                continue;
            }

            if ($node->newModel()->uniqueKey() === []) {
                throw SnapshotHistory::needsUniqueKey($node->model);
            }
        }
    }

    /**
     * Kahn's algorithm, draining one wave at a time so the levels fall out of it.
     *
     * @param  array<class-string, Node>  $nodes
     * @return list<list<Node>>
     */
    protected function sort(array $nodes): array
    {
        $inDegree = [];
        $dependents = [];

        foreach ($nodes as $class => $node) {
            $dependencies = array_values(array_filter(
                $node->dependencies(),
                static fn (string $dependency): bool => isset($nodes[$dependency]),
            ));

            $inDegree[$class] = count($dependencies);

            foreach ($dependencies as $dependency) {
                $dependents[$dependency][] = $class;
            }
        }

        $wave = array_keys(array_filter($inDegree, static fn (int $degree): bool => $degree === 0));
        sort($wave);

        $levels = [];
        $ordered = [];

        while ($wave !== []) {
            $level = [];
            $next = [];

            foreach ($wave as $class) {
                $level[] = $nodes[$class];
                $ordered[] = $class;

                foreach ($dependents[$class] ?? [] as $dependent) {
                    if (--$inDegree[$dependent] === 0) {
                        $next[] = $dependent;
                    }
                }
            }

            sort($next);

            $levels[] = $level;
            $wave = $next;
        }

        if (count($ordered) !== count($nodes)) {
            throw CircularDependency::among(array_values(array_diff(array_keys($nodes), $ordered)));
        }

        return $levels;
    }
}
