<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Issue;

/**
 * Orquesta la importación del esquema desde las migraciones.
 */
final class MigrationImporter
{
    public function __construct(
        private readonly MigrationFileParser $parser,
        private readonly MigrationSchemaBuilder $builder,
        private readonly SchemaMerger $merger,
        private readonly SchemaCompiler $compiler,
    ) {
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{document: array<string, mixed>, summary: array<string, int>, issues: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function import(string $path, array $existing, bool $prune = true): array
    {
        $files = $this->migrationFiles($path);
        $operations = [];
        $parseWarnings = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($this->parser->parse($contents) as $operation) {
                $operation['file'] = basename($file);
                $operations[] = $operation;
            }

            foreach ($this->parser->warnings() as $message) {
                $parseWarnings[] = [
                    'path' => 'migrations/'.basename($file),
                    'message' => $message,
                ];
            }
        }

        $tables = $this->builder->build($operations, $this->parser);
        $document = $this->merger->merge($existing, $tables, $prune);
        $compiled = $this->compiler->compile($document);

        $builderWarnings = array_map(
            static fn (array $warning): array => [
                'path' => $warning['file'] !== null ? 'migrations/'.$warning['file'] : '$',
                'message' => $warning['message'],
            ],
            $this->builder->warnings(),
        );

        $warnings = array_merge($parseWarnings, $builderWarnings);

        return [
            'document' => $compiled->document,
            'summary' => [
                'tables' => count($compiled->diagram->tables),
                'fields' => $compiled->diagram->fieldCount(),
                'relations' => count($compiled->relations),
                'pivots' => count($compiled->pivotTables),
                'migrations' => count($files),
                'warnings' => count($warnings),
            ],
            'issues' => array_map(
                static fn (Issue $issue): array => $issue->toArray(),
                $compiled->issues,
            ),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php');

        if ($files === false) {
            return [];
        }

        usort($files, static fn (string $first, string $second): int => strcmp(
            basename($first),
            basename($second),
        ));

        return $files;
    }
}
