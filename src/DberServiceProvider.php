<?php

declare(strict_types=1);

namespace Huzzidiel\Dber;

use Huzzidiel\Dber\Console\ExportCommand;
use Huzzidiel\Dber\Console\InstallCommand;
use Huzzidiel\Dber\Console\ValidateCommand;
use Huzzidiel\Dber\Support\DiagramRepository;
use Huzzidiel\Dber\Support\SchemaCompiler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el visor, el editor, los comandos y los archivos publicables.
 */
final class DberServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dber.php', 'dber');

        $this->app->singleton(DiagramRepository::class, function (Application $app): DiagramRepository {
            $path = (string) $app['config']->get('dber.path', '');

            return new DiagramRepository(
                path: $this->resolvePath($path),
                maxSize: (int) $app['config']->get('dber.max_size', 2097152),
                compiler: $app->make(SchemaCompiler::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dber');

        $this->publishes([
            __DIR__.'/../config/dber.php' => config_path('dber.php'),
        ], 'dber-config');

        $this->publishes([
            __DIR__.'/../resources/stubs' => base_path('diagram-er'),
        ], 'dber-stubs');

        if ((bool) config('dber.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ValidateCommand::class,
                ExportCommand::class,
            ]);
        }
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return base_path('diagram-er/base.json');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
