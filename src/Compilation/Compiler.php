<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics\Compilation;

use Eznix86\LaravelAnalytics\Contracts\AnalyticsModel;

class Compiler
{
    public function compile(AnalyticsModel $model, bool $fullRefresh = false, ?BatchWindow $window = null): Compiled
    {
        $context = new Context($fullRefresh, $window);

        $sql = $context->render($model);

        return new Compiled(
            $context->wrap($sql),
            $context->bindings(),
            $context->dependencies(),
            $context->sources(),
        );
    }
}
