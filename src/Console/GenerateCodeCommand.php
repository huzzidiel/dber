<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Console;

use Huzzidiel\Dber\Support\CodeGenerator;
use Huzzidiel\Dber\Support\DiagramRepository;
use Illuminate\Console\Command;

/**
 * Genera migraciones y modelos a partir del diagrama.
 */
final class GenerateCodeCommand extends Command
{
    protected $signature = 'dber:generate
                            {--dry-run : Print the files that would be written}
                            {--only-migrations : Generate only migrations}
                            {--only-models : Generate only models}';

    protected $description = 'Generate migrations and models from the diagram schema';

    public function handle(DiagramRepository $repository, CodeGenerator $generator): int
    {
        if (! $repository->exists()) {
            $this->error('No schema file found at '.$repository->path().'.');
            $this->line('Run php artisan dber:install or php artisan dber:import first.');

            return self::FAILURE;
        }

        $compiled = $repository->load();

        if ($compiled->hasErrors()) {
            $this->error('Fix the schema errors before generating code.');
            $this->renderIssues($compiled->errors());

            return self::FAILURE;
        }

        $plan = $generator->plan(
            $compiled,
            (string) config('dber.migrations_path', database_path('migrations')),
            app_path('Models'),
        );

        if ($this->option('only-migrations')) {
            $plan['models'] = [];
        }

        if ($this->option('only-models')) {
            $plan['migrations'] = [];
        }

        $this->printSummary($plan['summary']);

        if ($plan['warnings'] !== []) {
            $this->warn('Some tables were skipped.');
            $this->line(implode("\n", array_map(
                static fn (string $warning): string => '- '.$warning,
                $plan['warnings'],
            )));
        }

        foreach (array_merge($plan['migrations'], $plan['models']) as $file) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '[write]   ').$file['path']);
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $generator->write($plan);
        $this->info('Code generation finished.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $summary
     */
    private function printSummary(array $summary): void
    {
        $this->info(sprintf(
            'Migrations: %d (create %d, alter %d). Models: %d (create %d, update %d).',
            $summary['migrations'] ?? 0,
            $summary['migrations_created'] ?? 0,
            $summary['migrations_altered'] ?? 0,
            $summary['models'] ?? 0,
            $summary['models_created'] ?? 0,
            $summary['models_updated'] ?? 0,
        ));
    }

    /**
     * @param list<\Huzzidiel\Dber\Data\Issue> $issues
     */
    private function renderIssues(array $issues): void
    {
        $this->table(['Severity', 'Path', 'Message'], array_map(
            static fn ($issue): array => [$issue->severity, $issue->path, $issue->message],
            $issues,
        ));
    }
}
