<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:analytics')]
class MakeAnalyticsCommand extends GeneratorCommand
{
    /**
     * The command name.
     */
    protected $name = 'make:analytics';

    /**
     * The command description.
     */
    protected $description = 'Create a new analytics model';

    /**
     * The type of class being generated.
     */
    protected $type = 'Analytics model';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/analytics.stub';
    }

    protected function rootNamespace(): string
    {
        return trim((string) config('analytics.namespace'), '\\').'\\';
    }

    /**
     * @param  string  $name
     */
    protected function getPath($name): string
    {
        $relative = Str::replaceFirst($this->rootNamespace(), '', $name);

        return rtrim((string) config('analytics.path'), '/\\')
            .DIRECTORY_SEPARATOR
            .str_replace('\\', DIRECTORY_SEPARATOR, $relative)
            .'.php';
    }
}
