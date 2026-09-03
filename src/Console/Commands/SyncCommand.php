<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Eznix86\LaravelAnalytics\Engines\NativeEngine;
use Eznix86\LaravelAnalytics\Exceptions\SnapshotHistory;
use Eznix86\LaravelAnalytics\Graph\Node;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Materialization;
use Eznix86\LaravelAnalytics\Models\AnalyticsRun;
use Eznix86\LaravelAnalytics\RunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class SyncCommand extends AnalyticsCommand
{
    /**
     * Workers used when --parallel is given without a number.
     */
    public const int DEFAULT_CONCURRENCY = 4;

    /**
     * The command signature.
     */
    protected $signature = 'analytics:sync {model? : The analytics model to sync}
                            {--connection= : Only sync models on this connection}
                            {--only : Sync just the given model, without its dependencies}
                            {--parallel= : Build each wave concurrently, optionally with this many workers}
                            {--full-refresh : Rebuild incremental models from scratch instead of appending}
                            {--event-time-start= : Rebuild microbatch models from this moment}
                            {--event-time-end= : Rebuild microbatch models up to this moment}
                            {--continue : Resume the last run, skipping models that already succeeded in it}
                            {--run= : Record under this run id instead of starting a new one}
                            {--porcelain : Emit one tab separated line per model instead of formatted output}';

    /**
     * The command description.
     */
    protected $description = 'Build analytics models in dependency order';

    public function handle(Resolver $resolver, NativeEngine $engine): int
    {
        $levels = $resolver->levels($this->connectionOption(), (bool) $this->option('full-refresh'));

        if ($levels === []) {
            $this->components->warn('No analytics models found in '.config('analytics.path').'.');

            return self::SUCCESS;
        }

        $model = $this->argument('model');

        if (is_string($model)) {
            $nodes = array_merge(...$levels);
            $node = $this->findNode($nodes, $model);

            if ($node === null) {
                return $this->reportUnknownModel($nodes, $model);
            }

            if ($this->option('full-refresh') && $node->materialization === Materialization::Snapshot) {
                throw SnapshotHistory::cannotFullRefresh($node->model);
            }

            $selected = $this->option('only')
                ? [$node]
                : $this->withUpstream($nodes, $node);

            $levels = $this->restrict($levels, $selected);
        }

        if ($this->option('full-refresh')) {
            $levels = $this->skipSnapshots($levels);
        }

        $levels = $this->buildable($levels);

        if ($levels === []) {
            $this->components->warn('Nothing to build: every selected model is ephemeral.');

            return self::SUCCESS;
        }

        $runId = $this->runId($levels);

        if ($this->option('continue')) {
            $done = $this->alreadySucceeded($levels, $runId);

            $levels = $this->filterLevels(
                $levels,
                static fn (Node $node): bool => ! isset($done[$node->model]),
            );

            if ($levels === []) {
                $this->components->info('The last run finished every model. Nothing to resume.');

                return self::SUCCESS;
            }

            $this->components->info(sprintf(
                'Resuming run %s, skipping %d model%s that already succeeded.',
                $runId,
                count($done),
                count($done) === 1 ? '' : 's',
            ));
        }

        $engine->backfill($this->moment('event-time-start'), $this->moment('event-time-end'));

        $concurrency = $this->concurrency();

        return $concurrency > 1
            ? $this->syncConcurrently($levels, $concurrency, $runId)
            : $this->syncInSeries($levels, $engine, $runId);
    }

    /**
     * A run id groups every model built by one invocation, across connections.
     *
     * @param  list<list<Node>>  $levels
     */
    protected function runId(array $levels): string
    {
        $given = $this->option('run');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        if ($this->option('continue')) {
            $last = $this->lastRunId($levels);

            if ($last !== null) {
                return $last;
            }
        }

        return (string) Str::ulid();
    }

    /**
     * @param  list<list<Node>>  $levels
     */
    protected function lastRunId(array $levels): ?string
    {
        foreach ($this->connections($levels) as $connection) {
            $runId = AnalyticsRun::on($connection)->orderByDesc('id')->value('run_id');

            if (is_string($runId)) {
                return $runId;
            }
        }

        return null;
    }

    /**
     * @param  list<list<Node>>  $levels
     * @return array<class-string, true>
     */
    protected function alreadySucceeded(array $levels, string $runId): array
    {
        $done = [];

        foreach ($this->connections($levels) as $connection) {
            $models = AnalyticsRun::on($connection)
                ->where('run_id', $runId)
                ->where('status', RunStatus::Success)
                ->pluck('model');

            foreach ($models as $model) {
                $done[$model] = true;
            }
        }

        return $done;
    }

    /**
     * @param  list<list<Node>>  $levels
     * @return list<string|null>
     */
    protected function connections(array $levels): array
    {
        $connections = [];

        foreach ($levels as $level) {
            foreach ($level as $node) {
                $connections[(string) $node->connection] = $node->connection;
            }
        }

        return array_values($connections);
    }

    /**
     * @param  list<list<Node>>  $levels
     */
    protected function syncInSeries(array $levels, NativeEngine $engine, string $runId): int
    {
        $this->opening();

        $startedAt = hrtime(true);
        $built = 0;

        foreach ($levels as $level) {
            foreach ($level as $node) {
                try {
                    $result = $engine->sync($node, $runId);
                } catch (Throwable $failure) {
                    return $this->reportFailure($node, $failure->getMessage());
                }

                $built++;

                $this->report($node, $result->rows, $result->durationMs);
            }
        }

        return $this->closing($built, $startedAt);
    }

    /**
     * Everything in one wave depends only on earlier waves, so a wave can run at once.
     *
     * @param  list<list<Node>>  $levels
     */
    protected function syncConcurrently(array $levels, int $concurrency, string $runId): int
    {
        $this->opening();

        $startedAt = hrtime(true);
        $built = 0;

        foreach ($levels as $level) {
            $pending = $level;
            $running = [];

            while ($pending !== [] || $running !== []) {
                while (count($running) < $concurrency && $pending !== []) {
                    $node = array_shift($pending);

                    $running[] = [$node, Process::path(base_path())->start([
                        PHP_BINARY,
                        'artisan',
                        'analytics:sync',
                        $node->model,
                        '--only',
                        '--porcelain',
                        '--run='.$runId,
                        ...($this->option('full-refresh') ? ['--full-refresh'] : []),
                        ...($this->passThrough('event-time-start')),
                        ...($this->passThrough('event-time-end')),
                    ])];
                }

                usleep(20_000);

                foreach ($running as $slot => [$node, $process]) {
                    if ($process->running()) {
                        continue;
                    }

                    unset($running[$slot]);

                    $result = $process->wait();

                    if ($result->failed()) {
                        // Leaving workers running would let their cleanup race whatever comes next.
                        foreach ($running as [, $orphan]) {
                            $orphan->wait();
                        }

                        return $this->reportFailure(
                            $node,
                            trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()),
                        );
                    }

                    [$rows, $durationMs] = $this->parsePorcelain($result->output());
                    $built++;

                    $this->report($node, $rows, $durationMs);
                }
            }
        }

        return $this->closing($built, $startedAt);
    }

    protected function reportFailure(Node $node, string $message): int
    {
        $this->newLine();
        $this->components->error($node->name().' failed to build.');
        $this->line($message);
        $this->newLine();
        $this->components->info('Fix it, then resume with: php artisan analytics:sync --continue');

        return self::FAILURE;
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    protected function parsePorcelain(string $output): array
    {
        $fields = explode("\t", trim($output));

        return [
            ($fields[1] ?? '') === '' ? null : (int) $fields[1],
            (int) ($fields[2] ?? 0),
        ];
    }

    protected function report(Node $node, ?int $rows, int $durationMs): void
    {
        if ($this->option('porcelain')) {
            $this->output->getOutput()->writeln(
                $node->name()."\t".($rows ?? '')."\t".$durationMs,
                OutputInterface::OUTPUT_RAW,
            );

            return;
        }

        $this->components->twoColumnDetail(
            $node->name().' <fg=gray>'.$node->label().'</>',
            ($rows === null ? '' : number_format($rows).' rows  ')
                .number_format($durationMs / 1000, 2).'s',
        );
    }

    protected function opening(): void
    {
        if (! $this->option('porcelain')) {
            $this->newLine();
        }
    }

    protected function closing(int $built, float $startedAt): int
    {
        if ($this->option('porcelain')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d model%s synced in %ss.',
            $built,
            $built === 1 ? '' : 's',
            number_format((hrtime(true) - $startedAt) / 1_000_000_000, 2),
        ));

        return self::SUCCESS;
    }

    protected function moment(string $option): ?Carbon
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    protected function concurrency(): int
    {
        if (! $this->input->hasParameterOption('--parallel')) {
            return 1;
        }

        $workers = $this->option('parallel');

        return is_numeric($workers) ? max(1, (int) $workers) : self::DEFAULT_CONCURRENCY;
    }

    /**
     * @return list<string>
     */
    protected function passThrough(string $option): array
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? ['--'.$option.'='.$value] : [];
    }

    /**
     * A snapshot's history cannot be recomputed from the source, so a full refresh of
     * the graph leaves it alone rather than destroying it.
     *
     * @param  list<list<Node>>  $levels
     * @return list<list<Node>>
     */
    protected function skipSnapshots(array $levels): array
    {
        $skipped = 0;

        $kept = $this->filterLevels($levels, static function (Node $node) use (&$skipped): bool {
            if ($node->materialization === Materialization::Snapshot) {
                $skipped++;

                return false;
            }

            return true;
        });

        if ($skipped > 0 && ! $this->option('porcelain')) {
            $this->components->warn(sprintf(
                '%d snapshot%s left alone: a full refresh cannot rebuild history.',
                $skipped,
                $skipped === 1 ? '' : 's',
            ));
        }

        return $kept;
    }

    /**
     * @param  list<list<Node>>  $levels
     * @param  list<Node>  $selected
     * @return list<list<Node>>
     */
    protected function restrict(array $levels, array $selected): array
    {
        $wanted = [];

        foreach ($selected as $node) {
            $wanted[$node->model] = true;
        }

        return $this->filterLevels(
            $levels,
            static fn (Node $node): bool => isset($wanted[$node->model]),
        );
    }

    /**
     * @param  list<list<Node>>  $levels
     * @return list<list<Node>>
     */
    protected function buildable(array $levels): array
    {
        return $this->filterLevels($levels, static fn (Node $node): bool => $node->isBuildable());
    }

    /**
     * @param  list<list<Node>>  $levels
     * @param  callable(Node): bool  $keep
     * @return list<list<Node>>
     */
    protected function filterLevels(array $levels, callable $keep): array
    {
        $filtered = [];

        foreach ($levels as $level) {
            $matching = array_values(array_filter($level, $keep));

            if ($matching !== []) {
                $filtered[] = $matching;
            }
        }

        return $filtered;
    }
}
