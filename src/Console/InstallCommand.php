<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Console;

use Huzzidiel\Dber\Support\DiagramRepository;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Instala la configuración y los archivos base del diagrama.
 */
final class InstallCommand extends Command
{
    protected $signature = 'dber:install
                            {--force : Overwrite files that already exist}';

    protected $description = 'Create the diagram schema file, JSON schema and AI editing guide';

    public function handle(DiagramRepository $repository): int
    {
        $this->publishConfig();

        $schemaPath = $repository->path();
        $directory = dirname($schemaPath);
        $stubs = __DIR__.'/../../resources/stubs';

        if (!is_dir($directory) && ! @mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error('Could not create the directory: '.$directory);

            return self::FAILURE;
        }

        $base = $this->installBaseFile($stubs.'/base.json', $schemaPath, $repository->format());

        if (! $base) {
            return self::FAILURE;
        }

        $this->copyStub($stubs.'/schema.json', $directory.'/schema.json', 'JSON schema');
        $this->copyStub($stubs.'/AGENTS.md', $directory.'/AGENTS.md', 'AI editing guide');

        $this->newLine();
        $this->info('Dber is ready.');
        $this->line('Schema: '.$this->relative($schemaPath));

        if ((bool) config('dber.enabled')) {
            $this->line('Viewer: '.route('dber.index'));
        } else {
            $this->warn('The viewer route is disabled. Set DBER_ENABLED=true or enable it in config/dber.php.');
        }

        $this->line('Validate: php artisan dber:validate');
        $this->line('AI context: php artisan dber:export context');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $source = __DIR__.'/../../config/dber.php';
        $target = config_path('dber.php');

        if (is_file($target) && ! $this->option('force')) {
            $this->line('Config already exists: '.$this->relative($target));

            return;
        }

        if (! @copy($source, $target)) {
            $this->warn('Could not publish config/dber.php. Using the package defaults.');
            return;
        }

        $this->line('Published config: '.$this->relative($target));
    }

    private function installBaseFile(string $stub, string $target, string $format): bool
    {
        if (is_file($target) && ! $this->option('force')) {
            $this->line('Schema already exists: '.$this->relative($target));

            return true;
        }

        if ($format === 'json') {
            if (! @copy($stub, $target)) {
                $this->error('Could not create '.$this->relative($target));
                return false;
            }

            $this->line('Created schema: '.$this->relative($target));

            return true;
        }

        if ($format === 'yaml' || $format === 'yml') {
            if (!class_exists(Yaml::class)) {
                $this->error('YAML requires symfony/yaml. Install it or use a .json schema path.');

                return false;
            }

            $contents = @file_get_contents($stub);

            if ($contents === false) {
                $this->error('Could not read the base schema stub.');
                return false;
            }

            $document = json_decode($contents, true);

            if (!is_array($document)) {
                $this->error('The base schema stub is invalid.');
                return false;
            }

            $yaml = Yaml::dump($document, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."\n";

            if (@file_put_contents($target, $yaml) === false) {
                $this->error('Could not create '.$this->relative($target));
                return false;
            }

            $this->line('Created schema: '.$this->relative($target));

            return true;
        }

        $this->error('Unsupported schema extension "'.$format.'". Use .json, .yaml or .yml.');

        return false;
    }

    private function copyStub(string $source, string $target, string $label): void
    {
        if (is_file($target) && ! $this->option('force')) {
            return;
        }

        if (! @copy($source, $target)) {
            $this->warn('Could not create '.$label.': '.$this->relative($target));
            return;
        }

        $this->line('Created '.$label.': '.$this->relative($target));
    }

    private function relative(string $path): string
    {
        $base = base_path();

        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
