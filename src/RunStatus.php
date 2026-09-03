<?php

declare(strict_types=1);

namespace Eznix86\LaravelAnalytics;

enum RunStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
