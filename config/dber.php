<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Dber registers a route that exposes the diagram viewer and editor. It is
    | disabled outside of the local environment by default so that a deployed
    | application never exposes schema editing accidentally.
    |
    */

    'enabled' => (bool) env('DBER_ENABLED', env('APP_ENV', 'production') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Schema file
    |--------------------------------------------------------------------------
    |
    | The single source of truth for the diagram. JSON works out of the box.
    | YAML is also supported when symfony/yaml is installed in the project.
    |
    */

    'path' => env('DBER_PATH', base_path('diagram-er/base.json')),

    /*
    |--------------------------------------------------------------------------
    | Migrations directory
    |--------------------------------------------------------------------------
    |
    | Directory scanned by `dber:import` and the "Import migrations" button in
    | the viewer to build the schema from the existing migrations.
    |
    */

    'migrations_path' => env('DBER_MIGRATIONS_PATH', database_path('migrations')),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    */

    'route' => [

        'prefix' => env('DBER_ROUTE_PREFIX', 'dber'),

        'domain' => env('DBER_ROUTE_DOMAIN'),

        'middleware' => [
            'web',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Editor
    |--------------------------------------------------------------------------
    |
    | When disabled, the viewer is read-only and the save endpoint is blocked.
    | This flag does not replace the route middleware; protect the route with
    | authentication/authorization middleware when it is enabled outside of a
    | local environment.
    |
    */

    'editor' => (bool) env('DBER_EDITOR', true),

    /*
    |--------------------------------------------------------------------------
    | Live preview
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) the browser checks the schema file for
    | changes made outside the browser.
    |
    */

    'poll_interval' => (int) env('DBER_POLL_INTERVAL', 1500),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Maximum schema file size in bytes. Protects the request/parse path from
    | accidentally huge files.
    |
    */

    'max_size' => (int) env('DBER_MAX_SIZE', 2097152),

];
