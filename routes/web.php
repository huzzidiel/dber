<?php

declare(strict_types=1);

use Huzzidiel\Dber\Http\Controllers\AssetController;
use Huzzidiel\Dber\Http\Controllers\DiagramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Package assets
|--------------------------------------------------------------------------
|
| Assets are public and intentionally registered outside the application
| middleware group. Serving ES modules through the "web" group starts a
| session for every file, which can serialize module requests.
|
*/

Route::group([
    'prefix' => trim((string) config('dber.route.prefix'), '/'),
    'domain' => config('dber.route.domain'),
    'as' => 'dber.',
], function (): void {
    Route::get('/assets/{path}', [AssetController::class, 'show'])
        ->where('path', '.*')
        ->name('asset');
});

Route::group([
    'prefix' => trim((string) config('dber.route.prefix'), '/'),
    'domain' => config('dber.route.domain'),
    'middleware' => config('dber.route.middleware', ['web']),
    'as' => 'dber.',
], function (): void {
    Route::get('/', [DiagramController::class, 'index'])
        ->name('index');

    Route::get('/data', [DiagramController::class, 'data'])
        ->name('data');

    Route::put('/data', [DiagramController::class, 'update'])
        ->name('data.update');

    Route::post('/import', [DiagramController::class, 'import'])
        ->name('import');

    Route::get('/context.md', [DiagramController::class, 'context'])
        ->name('context');

    Route::get('/diagram.mmd', [DiagramController::class, 'mermaid'])
        ->name('mermaid');

    Route::get('/schema.json', [DiagramController::class, 'schema'])
        ->name('schema');
});
