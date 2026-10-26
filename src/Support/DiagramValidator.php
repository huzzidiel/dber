<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Issue;

/**
 * Valida las reglas semánticas del esquema normalizado.
 *
 * Los errores bloquean el guardado; las advertencias señalan decisiones que
 * probablemente no son intencionales pero no impiden trabajar.
 */
final class DiagramValidator
{
    private const SUPPORTED_VERSION = 1;

    /**
     * @var list<Issue>
     */
    private array $issues = [];

    /**
     * Valida el documento y devuelve todos los problemas encontrados.
     *
     * @param array<string, mixed> $document
     * @return list<Issue>
     */
    public function validate(array $document): array
    {
        $this->issues = [];

        $version = (int) ($document['schema_version'] ?? 1);

        if ($version > self::SUPPORTED_VERSION) {
            $this->error(
                '$.schema_version',
                'Schema version '.$version.' is not supported. This package understands version '
                .self::SUPPORTED_VERSION.'.',
            );
        }

        $tables = $document['tables'] ?? [];

        if (!is_array($tables)) {
            return $this->issues;
        }

        foreach ($tables as $tableName => $table) {
            if (!is_string($tableName) || !is_array($table)) {
                continue;
            }

            $this->validateTable($tableName, $table, $tables);
        }

        return $this->issues;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $tables
     */
    private function validateTable(string $name, array $data, array $tables): void
    {
        $path = '$.tables.'.$name;

        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $this->warning(
                $path,
                'Table names should use snake_case (lowercase letters, numbers and underscores).',
            );
        }

        $fields = $data['fields'] ?? [];

        if (!is_array($fields)) {
            return;
        }

        $primaryKey = $data['primary_key'] ?? [];
        $primaryKey = is_array($primaryKey) ? $primaryKey : [];
        $foreignKeyNames = [];

        foreach ($fields as $fieldName => $field) {
            if (!is_string($fieldName) || !is_array($field)) {
                continue;
            }

            if (isset($field['references']) && is_array($field['references'])) {
                $foreignKeyNames[] = $fieldName;
            }
        }

        foreach ($fields as $fieldName => $field) {
            if (!is_string($fieldName) || !is_array($field)) {
                continue;
            }

            $fieldPath = $path.'.fields.'.$fieldName;

            if (preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) !== 1) {
                $this->warning(
                    $fieldPath,
                    'Field names should use snake_case (lowercase letters, numbers and underscores).',
                );
            }

            $type = is_string($field['type'] ?? null) ? $field['type'] : '';

            if (!TypeRegistry::exists($type)) {
                $this->error(
                    $fieldPath.'.type',
                    'Unknown type "'.$type.'". Supported types: '.implode(', ', TypeRegistry::TYPES).'.',
                );
                continue;
            }

            $this->validateModifiers($fieldPath, $fieldName, $type, $field, $primaryKey);
            $this->validateReference($fieldPath, $fieldName, $field, $tables);
        }

        foreach ($primaryKey as $column) {
            if (!is_string($column)) {
                continue;
            }

            if (!array_key_exists($column, $fields)) {
                $this->error(
                    $path.'.primary_key',
                    'Primary key column "'.$column.'" does not exist in the table.',
                );
                continue;
            }

            $field = $fields[$column];

            if (is_array($field) && ($field['nullable'] ?? false) === true) {
                $this->warning(
                    $path.'.primary_key',
                    'Primary key column "'.$column.'" is nullable. Primary keys are normally NOT NULL.',
                );
            }
        }

        if (($data['pivot'] ?? false) === true && count($foreignKeyNames) < 2) {
            $this->warning(
                $path.'.pivot',
                'A pivot table needs at least two foreign keys so the many-to-many relation is explicit.',
            );
        }

        $indexes = $data['indexes'] ?? [];

        if (is_array($indexes)) {
            foreach ($indexes as $position => $index) {
                if (!is_array($index)) {
                    continue;
                }

                $indexColumns = $index['columns'] ?? [];

                if (!is_array($indexColumns)) {
                    continue;
                }

                foreach ($indexColumns as $column) {
                    if (!is_string($column)) {
                        continue;
                    }

                    if (!array_key_exists($column, $fields)) {
                        $this->error(
                            $path.'.indexes.'.$position.'.columns',
                            'Index column "'.$column.'" does not exist in the table.',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, mixed> $primaryKey
     */
    private function validateModifiers(
        string $path,
        string $fieldName,
        string $type,
        array $field,
        array $primaryKey,
    ): void
    {
        $length = $field['length'] ?? null;

        if ($length !== null && ! TypeRegistry::supportsLength($type)) {
            $this->warning($path.'.length', 'The '.$type.' type does not use a length.');
        }

        $precision = $field['precision'] ?? null;
        $scale = $field['scale'] ?? null;

        if ($precision !== null && ! TypeRegistry::supportsPrecision($type)) {
            $this->warning($path.'.precision', 'The '.$type.' type does not use precision.');
        }

        if ($scale !== null && $precision === null) {
            $this->warning($path.'.scale', 'Scale is ignored when precision is not declared.');
        }

        if (($field['unsigned'] ?? false) === true && ! TypeRegistry::supportsUnsigned($type)) {
            $this->warning($path.'.unsigned', 'The '.$type.' type does not support unsigned.');
        }

        if (($field['auto_increment'] ?? false) === true) {
            if (! TypeRegistry::supportsAutoIncrement($type)) {
                $this->error($path.'.auto_increment', 'Auto increment requires an integer type.');
            }

            if (!in_array($fieldName, $primaryKey, true)) {
                $this->warning(
                    $path.'.auto_increment',
                    'Auto increment columns are normally the primary key.',
                );
            }
        }

        $values = $field['values'] ?? [];

        if (in_array($type, ['enum', 'set'], true) && (!is_array($values) || $values === [])) {
            $this->error($path.'.values', 'The '.$type.' type needs at least one value.');
        }

        if (!in_array($type, ['enum', 'set'], true) && is_array($values) && $values !== []) {
            $this->warning($path.'.values', 'Values are only meaningful for enum and set fields.');
        }

        if (($field['references'] ?? null) !== null && in_array($type, ['json', 'boolean'], true)) {
            $this->warning(
                $path.'.references',
                'A foreign key with type '.$type.' is unusual. Use an integer, uuid or ulid type.',
            );
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $tables
     */
    private function validateReference(string $path, string $fieldName, array $field, array $tables): void
    {
        $reference = $field['references'] ?? null;

        if (!is_array($reference)) {
            return;
        }

        $tableName = is_string($reference['table'] ?? null) ? $reference['table'] : '';
        $targetField = is_string($reference['field'] ?? null) ? $reference['field'] : '';

        if ($tableName === '' || $targetField === '') {
            return;
        }

        if (!array_key_exists($tableName, $tables) || !is_array($tables[$tableName])) {
            $this->error(
                $path.'.references.table',
                'Referenced table "'.$tableName.'" does not exist.',
            );
            return;
        }

        $target = $tables[$tableName];
        $targetFields = $target['fields'] ?? [];

        if (!is_array($targetFields) || !array_key_exists($targetField, $targetFields)) {
            $this->error(
                $path.'.references.field',
                'Referenced field "'.$tableName.'.'.$targetField.'" does not exist.',
            );
            return;
        }

        $targetData = is_array($targetFields[$targetField]) ? $targetFields[$targetField] : [];
        $isUnique = $this->isUniqueColumn($targetField, $target, $targetData);

        if (! $isUnique) {
            $this->warning(
                $path.'.references.field',
                'Referenced field "'.$tableName.'.'.$targetField
                .'" is not a primary or unique column. Foreign keys normally target unique columns.',
            );
        }

        if (($reference['on_delete'] ?? null) === 'set_null' && ($field['nullable'] ?? false) !== true) {
            $this->warning(
                $path.'.references.on_delete',
                'on_delete "set_null" requires the foreign key column to be nullable.',
            );
        }

        if (($reference['on_update'] ?? null) === 'set_null' && ($field['nullable'] ?? false) !== true) {
            $this->warning(
                $path.'.references.on_update',
                'on_update "set_null" requires the foreign key column to be nullable.',
            );
        }

        if ($fieldName === '') {
            $this->warning($path, 'A foreign key needs a column name.');
        }
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, mixed> $field
     */
    private function isUniqueColumn(string $fieldName, array $table, array $field): bool
    {
        if (($field['unique'] ?? false) === true) {
            return true;
        }

        $primaryKey = $table['primary_key'] ?? [];

        if (is_array($primaryKey) && $primaryKey === [$fieldName]) {
            return true;
        }

        $indexes = $table['indexes'] ?? [];

        if (!is_array($indexes)) {
            return false;
        }

        foreach ($indexes as $index) {
            if (!is_array($index) || ($index['unique'] ?? false) !== true) {
                continue;
            }

            $columns = $index['columns'] ?? [];

            if (is_array($columns) && $columns === [$fieldName]) {
                return true;
            }
        }

        return false;
    }

    private function error(string $path, string $message): void
    {
        $this->issues[] = new Issue(Issue::ERROR, $path, $message);
    }

    private function warning(string $path, string $message): void
    {
        $this->issues[] = new Issue(Issue::WARNING, $path, $message);
    }
}
