<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Models\AnalyticsRun;

class PruneCommand extends AnalyticsCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'analytics:prune {--connection= : Only prune the run log on this connection}';

    /**
     * The command description.
     */
    protected $description = 'Remove sync history older than the configured retention window';

    public function handle(Resolver $resolver): int
    {
        $retention = config('analytics.retention');

        if (! is_string($retention) || $retention === '') {
            $this->components->warn('No retention window configured, so nothing is pruned. Set analytics.retention.');

            return self::SUCCESS;
        }

        $this->newLine();

        $total = 0;

        foreach ($this->runLogConnections($resolver) as $connection) {
            $run = (new AnalyticsRun)->setConnection($connection);

            if (! $run->getConnection()->getSchemaBuilder()->hasTable($run->getTable())) {
                continue;
            }

            $pruned = $run->pruneAll();
            $total += $pruned;

            $this->components->twoColumnDetail(
                $connection ?? 'default',
                number_format($pruned).' run'.($pruned === 1 ? '' : 's').' pruned',
            );
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s run%s older than %s removed.',
            number_format($total),
            $total === 1 ? '' : 's',
            $retention,
        ));

        return self::SUCCESS;
    }

    /**
     * Every connection an analytics model writes its run log to.
     *
     * @return list<string|null>
     */
    protected function runLogConnections(Resolver $resolver): array
    {
        $requested = $this->connectionOption();

        if ($requested !== null) {
            return [$requested];
        }

        $connections = [];

        foreach ($resolver->levels() as $level) {
            foreach ($level as $node) {
                $connections[(string) $node->connection] = $node->connection;
            }
        }

        return $connections === [] ? [null] : array_values($connections);
    }
}
