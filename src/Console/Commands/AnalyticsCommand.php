<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Eznix86\LaravelAnalytics\Graph\Node;
use Illuminate\Console\Command;

abstract class AnalyticsCommand extends Command
{
    protected function connectionOption(): ?string
    {
        $connection = $this->option('connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * @param  list<Node>  $nodes
     */
    protected function findNode(array $nodes, string $name): ?Node
    {
        foreach ($nodes as $node) {
            if ($node->model === $name || strcasecmp($node->name(), $name) === 0) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The target plus everything it depends on, keeping the resolved build order.
     *
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    protected function withUpstream(array $nodes, Node $target): array
    {
        $indexed = [];

        foreach ($nodes as $node) {
            $indexed[$node->model] = $node;
        }

        $wanted = [];
        $pending = [$target->model];

        while ($pending !== []) {
            $model = array_pop($pending);

            if (isset($wanted[$model])) {
                continue;
            }

            $wanted[$model] = true;

            foreach ($indexed[$model]->dependencies() as $dependency) {
                if (isset($indexed[$dependency])) {
                    $pending[] = $dependency;
                }
            }
        }

        return array_values(array_filter(
            $nodes,
            static fn (Node $node): bool => isset($wanted[$node->model]),
        ));
    }

    /**
     * @param  list<Node>  $nodes
     */
    protected function reportUnknownModel(array $nodes, string $name): int
    {
        $known = array_map(static fn (Node $node): string => $node->name(), $nodes);
        sort($known);

        $this->components->error("No analytics model named [{$name}] was found.");

        if ($known !== []) {
            $this->components->bulletList($known);
        }

        return self::FAILURE;
    }
}
