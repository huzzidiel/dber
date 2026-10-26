<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Console;

use Huzzidiel\Dber\Data\Issue;
use Huzzidiel\Dber\Exceptions\InvalidDiagramException;
use Huzzidiel\Dber\Support\DiagramRepository;
use Illuminate\Console\Command;

/**
 * Valida el archivo de esquema y muestra errores y advertencias.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'dber:validate';

    protected $description = 'Validate the diagram schema file';

    public function handle(DiagramRepository $repository): int
    {
        if (! $repository->exists()) {
            $this->error('No schema file found at '.$repository->path().'.');
            $this->line('Run php artisan dber:install to create one.');

            return self::FAILURE;
        }

        try {
            $compiled = $repository->load();
        } catch (InvalidDiagramException $exception) {
            $this->error($exception->getMessage());
            $this->renderIssues($exception->issues);

            return self::FAILURE;
        }

        $errors = $compiled->errors();
        $warnings = array_values(array_filter(
            $compiled->issues,
            static fn (Issue $issue): bool => ! $issue->isError(),
        ));

        if ($compiled->issues !== []) {
            $this->renderIssues($compiled->issues);
        }

        $summary = count($compiled->diagram->tables).' tables, '
            .$compiled->diagram->fieldCount().' fields, '
            .count($compiled->relations).' relations, '
            .count($compiled->pivotTables).' pivot tables.';

        if ($errors !== []) {
            $this->newLine();
            $this->error('Invalid schema: '.count($errors).' error(s), '.count($warnings).' warning(s).');
            $this->line($summary);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Valid schema: '.$summary);

        return self::SUCCESS;
    }

    /**
     * @param list<Issue> $issues
     */
    private function renderIssues(array $issues): void
    {
        $rows = array_map(
            static fn (Issue $issue): array => [
                $issue->severity,
                $issue->path,
                $issue->message,
            ],
            $issues,
        );

        $this->table(['Severity', 'Path', 'Message'], $rows);
    }
}
