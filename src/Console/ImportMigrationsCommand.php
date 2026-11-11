<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Console;

use Huzzidiel\Dber\Support\DiagramRepository;
use Huzzidiel\Dber\Support\MigrationImporter;
use Illuminate\Console\Command;

/**
 * Genera o actualiza el esquema a partir de las migraciones existentes.
 */
final class ImportMigrationsCommand extends Command
{
    protected $signature = 'dber:import
                            {--path= : Migrations directory (defaults to database/migrations)}
                            {--keep-extra : Keep columns that are not present in the migrations}
                            {--dry-run : Print the result without writing the schema file}';

    protected $description = 'Generate or update the diagram schema from the existing migrations';

    public function handle(
        DiagramRepository $repository,
        MigrationImporter $importer,
    ): int
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $path = (string) config('dber.migrations_path', database_path('migrations'));
        }

        if (! is_dir($path)) {
            $this->error('Migrations directory not found: '.$path);

            return self::FAILURE;
        }

        $existing = [];

        if ($repository->exists()) {
            $existing = $repository->load()->document;
        }

        $result = $importer->import($path, $existing, ! $this->option('keep-extra'));
        $summary = $result['summary'];

        $this->info(sprintf(
            'Imported %d table(s), %d field(s), %d relation(s) from %d migration file(s).',
            $summary['tables'],
            $summary['fields'],
            $summary['relations'],
            $summary['migrations'],
        ));

        if ($summary['warnings'] > 0) {
            $this->warn($summary['warnings'].' migration statement(s) were skipped.');
            $this->table(['Path', 'Message'], array_map(
                static fn (array $warning): array => [$warning['path'], $warning['message']],
                $result['warnings'],
            ));
        }

        $issues = $result['issues'];
        $errors = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error',
        ));

        if ($errors !== []) {
            $this->error('The imported schema has validation errors.');
            $this->table(['Severity', 'Path', 'Message'], array_map(
                static fn (array $issue): array => [$issue['severity'], $issue['path'], $issue['message']],
                $issues,
            ));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run: the schema file was not modified.');

            return self::SUCCESS;
        }

        $repository->save($result['document'], $repository->hash(), true);
        $this->info('Schema written to '.$repository->path().'.');

        return self::SUCCESS;
    }
}
