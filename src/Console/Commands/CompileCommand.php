<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Doctrine\SqlFormatter\CliHighlighter;
use Doctrine\SqlFormatter\NullHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;
use Eznix86\LaravelAnalytics\Graph\Resolver;
use Symfony\Component\Console\Output\OutputInterface;

class CompileCommand extends AnalyticsCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'analytics:compile {model? : The analytics model to compile}
                            {--connection= : Only compile models on this connection}';

    /**
     * The command description.
     */
    protected $description = 'Show the SQL an analytics model compiles to, without running it';

    public function handle(Resolver $resolver): int
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

        $formatter = new SqlFormatter(
            $this->output->isDecorated() ? new CliHighlighter : new NullHighlighter,
        );

        foreach ($nodes as $node) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan;options=bold>'.$node->name().'</>',
                $node->label(),
            );
            $sql = rtrim($formatter->format($node->compiled->sql));

            $this->output->writeln(
                '  '.str_replace("\n", "\n  ", $sql),
                OutputInterface::OUTPUT_RAW,
            );
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
