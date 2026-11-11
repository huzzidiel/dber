<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

/**
 * Fusiona el esquema derivado de las migraciones con el documento existente.
 *
 * Las migraciones son la autoridad para la estructura; la metadata manual
 * (model, description, name, database) se conserva.
 */
final class SchemaMerger
{
    /**
     * @param array<string, mixed> $existing
     * @param array<string, array{fields: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>, primary_key: list<string>, pivot: bool}> $migrated
     * @return array<string, mixed>
     */
    public function merge(array $existing, array $migrated, bool $prune = true): array
    {
        $document = $existing;
        $document['$schema'] = $existing['$schema'] ?? './schema.json';
        $document['schema_version'] = $existing['schema_version'] ?? 1;
        $document['name'] = $existing['name'] ?? 'Laravel application';

        if (array_key_exists('database', $existing)) {
            $document['database'] = $existing['database'];
        }

        $existingTables = $existing['tables'] ?? [];
        $result = [];

        foreach ($migrated as $name => $table) {
            $merged = $table;

            if (isset($existingTables[$name]) && is_array($existingTables[$name])) {
                $existingTable = $existingTables[$name];

                foreach (['model', 'description'] as $key) {
                    if (isset($existingTable[$key])) {
                        $merged[$key] = $existingTable[$key];
                    }
                }

                if (($existingTable['pivot'] ?? false) === true) {
                    $merged['pivot'] = true;
                }

                if (! $prune) {
                    foreach ($existingTable['fields'] ?? [] as $fieldName => $field) {
                        if (! isset($merged['fields'][$fieldName])) {
                            $merged['fields'][$fieldName] = $field;
                        }
                    }

                    foreach ($existingTable['indexes'] ?? [] as $index) {
                        $merged['indexes'][] = $index;
                    }
                }
            }

            $result[$name] = $merged;
        }

        foreach ($existingTables as $name => $existingTable) {
            if (! isset($result[$name])) {
                $result[$name] = $existingTable;
            }
        }

        $document['tables'] = $result;

        return $document;
    }
}
