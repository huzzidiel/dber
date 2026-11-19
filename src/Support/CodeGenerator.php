<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Table;

/**
 * Orquesta la generación de migraciones y modelos a partir del diagrama.
 */
final class CodeGenerator
{
    public function __construct(
        private readonly MigrationImporter $importer,
        private readonly MigrationCodeGenerator $migrationGenerator,
        private readonly ModelCodeGenerator $modelGenerator,
    ) {
    }

    /**
     * @return array{
     *     migrations: list<array{path: string, table: string, action: string, contents: string}>,
     *     models: list<array{path: string, class: string, action: string, contents: string}>,
     *     warnings: list<string>,
     *     summary: array<string, int>
     * }
     */
    public function plan(CompiledDiagram $diagram, string $migrationsPath, string $modelsBasePath): array
    {
        $existing = $this->importer->import($migrationsPath, [], true)['document']['tables'] ?? [];
        $existing = is_array($existing) ? $existing : [];

        $migrations = [];
        $models = [];
        $warnings = [];
        $timestamp = time();
        $sequence = 0;

        foreach ($diagram->diagram->tables as $table) {
            $existingTable = $existing[$table->name] ?? null;

            if ($existingTable === null) {
                $migrations[] = [
                    'path' => $this->migrationPath($migrationsPath, $timestamp, $sequence++, 'create_'.$table->name.'_table'),
                    'table' => $table->name,
                    'action' => 'create',
                    'contents' => $this->migrationGenerator->createMigration($table),
                ];
            } else {
                $missing = $this->missingFields($table, $existingTable);

                if ($missing !== []) {
                    $label = implode('_and_', array_slice(array_map(
                        static fn ($field): string => $field->name,
                        $missing,
                    ), 0, 2)).'_to_'.$table->name.'_table';

                    $migrations[] = [
                        'path' => $this->migrationPath($migrationsPath, $timestamp, $sequence++, 'add_'.$label),
                        'table' => $table->name,
                        'action' => 'alter',
                        'contents' => $this->migrationGenerator->alterMigration($table, $missing),
                    ];
                }
            }

            $model = $this->modelPlan($table, $modelsBasePath);

            if (is_array($model)) {
                $models[] = $model;
            } else {
                $warnings[] = 'Skipped model for table "'.$table->name.'": '.$model;
            }
        }

        return [
            'migrations' => $migrations,
            'models' => $models,
            'warnings' => $warnings,
            'summary' => [
                'migrations' => count($migrations),
                'migrations_created' => count(array_filter($migrations, static fn (array $m): bool => $m['action'] === 'create')),
                'migrations_altered' => count(array_filter($migrations, static fn (array $m): bool => $m['action'] === 'alter')),
                'models' => count($models),
                'models_created' => count(array_filter($models, static fn (array $m): bool => $m['action'] === 'create')),
                'models_updated' => count(array_filter($models, static fn (array $m): bool => $m['action'] === 'update')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $existingTable
     * @return list<\Huzzidiel\Dber\Data\Field>
     */
    private function missingFields(Table $table, array $existingTable): array
    {
        $existingFields = is_array($existingTable['fields'] ?? null) ? $existingTable['fields'] : [];

        return array_values(array_filter(
            $table->fields,
            static fn (Field $field): bool => ! array_key_exists($field->name, $existingFields),
        ));
    }

    /**
     * @return array{path: string, class: string, action: string, contents: string}|string
     */
    private function modelPlan(Table $table, string $modelsBasePath): array|string
    {
        if ($table->pivot) {
            return 'pivot table';
        }

        $info = $this->modelGenerator->className($table);
        $namespace = $info['namespace'];
        $class = $info['class'];

        $path = match ($namespace) {
            'App\\Models' => rtrim($modelsBasePath, '/').'/'.$class.'.php',
            'App' => dirname($modelsBasePath).'/'.$class.'.php',
            default => null,
        };

        if ($path === null) {
            return 'unsupported namespace '.$namespace;
        }

        if (is_file($path)) {
            $contents = (string) file_get_contents($path);

            return [
                'path' => $path,
                'class' => $class,
                'action' => 'update',
                'contents' => $this->modelGenerator->updateExisting($contents, $table, $class),
            ];
        }

        return [
            'path' => $path,
            'class' => $class,
            'action' => 'create',
            'contents' => $this->modelGenerator->generateNew($table, $namespace, $class),
        ];
    }

    private function migrationPath(string $migrationsPath, int $timestamp, int $sequence, string $name): string
    {
        $prefix = date('Y_m_d_His', $timestamp).'_'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

        return rtrim($migrationsPath, '/').'/'.$prefix.'_'.$name.'.php';
    }

    /**
     * @param array{migrations: list<array{path: string, contents: string}>, models: list<array{path: string, contents: string}>} $plan
     */
    public function write(array $plan): void
    {
        foreach (array_merge($plan['migrations'], $plan['models']) as $file) {
            $directory = dirname($file['path']);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                continue;
            }

            file_put_contents($file['path'], $file['contents']);
        }
    }
}
