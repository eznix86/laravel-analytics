<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Eznix86\LaravelAnalytics\Graph\Resolver;
use Eznix86\LaravelAnalytics\Testing\Result;
use Eznix86\LaravelAnalytics\Testing\Runner;

class TestCommand extends AnalyticsCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'analytics:test {model? : The analytics model to check}
                            {--connection= : Only check models on this connection}';

    /**
     * The command description.
     */
    protected $description = 'Check the data expectations declared by analytics models';

    public function handle(Resolver $resolver, Runner $runner): int
    {
        $nodes = $resolver->resolve($this->connectionOption());

        if ($nodes === []) {
            $this->components->warn('No analytics models found in '.config('analytics.path').'.');

            return self::SUCCESS;
        }

        $model = $this->argument('model');

        if (is_string($model)) {
            $node = $this->findNode($nodes, $model);

            if ($node === null) {
                return $this->reportUnknownModel($nodes, $model);
            }

            $nodes = [$node];
        }

        $checked = 0;
        $failed = 0;

        $this->newLine();

        foreach ($nodes as $node) {
            if (! $node->isBuildable()) {
                continue;
            }

            $results = $runner->run($node->newModel());

            if ($results === []) {
                continue;
            }

            $this->components->twoColumnDetail('<fg=cyan;options=bold>'.$node->name().'</>');

            foreach ($results as $result) {
                $checked++;
                $failed += $result->passed() ? 0 : 1;

                $this->reportResult($result);
            }
        }

        $this->newLine();

        if ($checked === 0) {
            $this->components->warn('No expectations declared. Add expectations() to an analytics model.');

            return self::SUCCESS;
        }

        if ($failed > 0) {
            $this->components->error(sprintf(
                '%d of %d expectation%s failed.',
                $failed,
                $checked,
                $checked === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d expectation%s passed.',
            $checked,
            $checked === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    protected function reportResult(Result $result): void
    {
        $this->components->twoColumnDetail(
            '  '.$result->expectation->describe(),
            $result->passed()
                ? '<fg=green>PASS</>'
                : '<fg=red>FAIL</> '.number_format($result->offendingRows).' rows',
        );
    }
}
