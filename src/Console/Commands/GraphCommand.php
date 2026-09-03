<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Eznix86\LaravelAnalytics\Graph\Node;
use Eznix86\LaravelAnalytics\Graph\Resolver;

class GraphCommand extends AnalyticsCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'analytics:graph {--connection= : Only show models on this connection}';

    /**
     * The command description.
     */
    protected $description = 'Show analytics models in dependency order';

    public function handle(Resolver $resolver): int
    {
        $nodes = $resolver->resolve($this->connectionOption());

        if ($nodes === []) {
            $this->components->warn('No analytics models found in '.config('analytics.path').'.');

            return self::SUCCESS;
        }

        foreach ($this->groupByConnection($nodes) as $connection => $group) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan;options=bold>'.$connection.'</>',
                count($group).' model'.(count($group) === 1 ? '' : 's'),
            );

            foreach ($group as $node) {
                $this->components->twoColumnDetail(
                    '  '.$node->name().' <fg=gray>'.$node->label().'</>',
                    $this->describeDependencies($node),
                );
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<Node>  $nodes
     * @return array<string, list<Node>>
     */
    protected function groupByConnection(array $nodes): array
    {
        $grouped = [];

        foreach ($nodes as $node) {
            $grouped[$node->connection ?? 'default'][] = $node;
        }

        return $grouped;
    }

    protected function describeDependencies(Node $node): string
    {
        $names = array_map(
            static fn (string $model): string => class_basename($model),
            [...$node->dependencies(), ...$node->compiled->sources],
        );

        sort($names);

        return $names === [] ? '<fg=gray>no dependencies</>' : implode(', ', $names);
    }
}
