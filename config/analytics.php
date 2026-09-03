<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | The executor that materializes analytics models. "native" builds them
    | with Laravel's own database connections and requires nothing else.
    |
    */

    'engine' => env('ANALYTICS_ENGINE', 'native'),

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The connection analytics models are built on and read from, when a model
    | does not declare its own. Null uses the application default connection.
    |
    */

    'connection' => env('ANALYTICS_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Where analytics models live, and the namespace that directory maps to.
    |
    */

    'path' => app_path('Analytics'),

    'namespace' => 'App\\Analytics',

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long sync history is kept in the analytics_runs table before
    | `analytics:prune` removes it. Null keeps every run forever.
    |
    */

    'retention' => env('ANALYTICS_RETENTION', '1 year'),

    /*
    |--------------------------------------------------------------------------
    | Naming
    |--------------------------------------------------------------------------
    |
    | Analytics relations are named "{prefix}{model}". Set a schema instead to
    | isolate them in their own namespace on drivers that support schemas; the
    | schema replaces the prefix when set.
    |
    */

    'prefix' => 'analytics_',

    'schema' => env('ANALYTICS_SCHEMA'),

];
