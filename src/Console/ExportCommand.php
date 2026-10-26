<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Console;

use Huzzidiel\Dber\Data\Issue;
use Huzzidiel\Dber\Exceptions\InvalidDiagramException;
use Huzzidiel\Dber\Support\DiagramRepository;
use Huzzidiel\Dber\Support\MarkdownExporter;
use Huzzidiel\Dber\Support\MermaidExporter;
use Illuminate\Console\Command;
use JsonException;

/**
 * Exporta el esquema como contexto para IA, Mermaid o JSON canónico.
 */
final class ExportCommand extends Command
{
    protected $signature = 'dber:export
                            {format=context : context, mermaid or json}
                            {--path= : Write the export to a file instead of stdout}';

    protected $description = 'Export the schema as AI context, a Mermaid diagram or canonical JSON';

    public function handle(
        DiagramRepository $repository,
        MarkdownExporter $markdownExporter,
        MermaidExporter $mermaidExporter,
    ): int
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

        if ($compiled->hasErrors()) {
            $this->error('Fix the schema errors before exporting.');
            $this->renderIssues($compiled->errors());

            return self::FAILURE;
        }

        $format = strtolower((string) $this->argument('format'));

        try {
            $contents = match ($format) {
                'context', 'markdown', 'md' => $markdownExporter->export($compiled, $this->displayPath($repository->path())),
                'mermaid', 'mmd' => $mermaidExporter->export($compiled),
                'json' => $this->json($compiled->document),
                default => null,
            };
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($contents === null) {
            $this->error('Unknown format "'.$format.'". Use context, mermaid or json.');

            return self::FAILURE;
        }

        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            $directory = dirname($path);

            if (!is_dir($directory) && ! @mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->error('Could not create the directory: '.$directory);

                return self::FAILURE;
            }

            if (@file_put_contents($path, $contents) === false) {
                $this->error('Could not write '.$path);

                return self::FAILURE;
            }

            $this->info('Exported to '.$path);

            return self::SUCCESS;
        }

        $this->output->write($contents);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $document
     * @throws JsonException
     */
    private function json(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
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

    private function displayPath(string $path): string
    {
        $base = base_path();

        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
