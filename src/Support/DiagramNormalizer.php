<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Issue;

/**
 * Convierte el contenido crudo del archivo en un documento canónico.
 *
 * Es tolerante con tipos escalares razonables (por ejemplo "255" para una
 * longitud), pero registra un problema cuando algo impide interpretar el
 * esquema. Las claves desconocidas se advierten y se descartan para mantener
 * el archivo estable y predecible.
 */
final class DiagramNormalizer
{
    /**
     * Claves válidas en la raíz del documento.
     *
     * @var list<string>
     */
    private const ROOT_KEYS = [
        '$schema',
        'schema_version',
        'name',
        'database',
        'tables',
    ];

    /**
     * Claves válidas de una tabla.
     *
     * @var list<string>
     */
    private const TABLE_KEYS = [
        'model',
        'description',
        'primary_key',
        'pivot',
        'fields',
        'indexes',
    ];

    /**
     * Claves válidas de una columna.
     *
     * @var list<string>
     */
    private const FIELD_KEYS = [
        'type',
        'length',
        'precision',
        'scale',
        'nullable',
        'unique',
        'unsigned',
        'auto_increment',
        'default',
        'references',
        'values',
    ];

    /**
     * Claves válidas de una referencia.
     *
     * @var list<string>
     */
    private const REFERENCE_KEYS = [
        'table',
        'field',
        'on_delete',
        'on_update',
    ];

    /**
     * Claves válidas de un índice.
     *
     * @var list<string>
     */
    private const INDEX_KEYS = [
        'name',
        'columns',
        'unique',
    ];

    /**
     * @var list<Issue>
     */
    private array $issues = [];

    /**
     * Normaliza el documento completo.
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $input): array
    {
        $this->issues = [];

        if (!is_array($input)) {
            $this->error('$', 'The schema must be a JSON object.');

            $input = [];
        } elseif ($input !== [] && array_is_list($input)) {
            $this->error('$', 'The schema root must be an object, not a list.');

            $input = [];
        }

        $this->warnUnknownKeys($input, self::ROOT_KEYS, '$');

        $version = $this->normalizeInteger($input['schema_version'] ?? 1, '$.schema_version', 1);

        if ($version === null || $version < 1) {
            $this->error('$.schema_version', 'The schema version must be a positive integer.');
            $version = 1;
        }

        $name = $this->normalizeString($input['name'] ?? 'Laravel application', '$.name', 'Laravel application');

        if ($name === null || $name === '') {
            $this->error('$.name', 'The project name must be a non-empty string.');
            $name = 'Laravel application';
        }

        $schema = $this->normalizeString($input['$schema'] ?? './schema.json', '$."$schema"', './schema.json');

        if ($schema === null || $schema === '') {
            $schema = './schema.json';
        }

        $database = null;

        if (array_key_exists('database', $input) && $input['database'] !== null && $input['database'] !== '') {
            if (is_string($input['database'])) {
                $database = trim($input['database']);
            } else {
                $this->error('$.database', 'The database engine must be a string.');
            }
        }

        $document = [
            '$schema' => $schema,
            'schema_version' => $version,
            'name' => $name,
        ];

        if ($database !== null && $database !== '') {
            $document['database'] = $database;
        }

        $tables = $input['tables'] ?? [];

        if (!is_array($tables)) {
            $this->error('$.tables', 'The tables property must be an object keyed by table name.');
            $tables = [];
        } elseif ($tables !== [] && array_is_list($tables)) {
            $this->error('$.tables', 'Tables must be keyed by table name, not stored in a list.');
            $tables = [];
        }

        $normalizedTables = [];

        foreach ($tables as $tableName => $table) {
            if (!is_string($tableName) || $tableName === '') {
                $this->error('$.tables', 'Every table needs a non-empty name.');
                continue;
            }

            if (!is_array($table)) {
                $this->error('$.tables.'.$tableName, 'The table definition must be an object.');
                continue;
            }

            $normalizedTables[$tableName] = $this->normalizeTable($tableName, $table);
        }

        $document['tables'] = $normalizedTables;

        return $document;
    }

    /**
     * Problemas detectados durante la normalización.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeTable(string $name, array $data): array
    {
        $path = '$.tables.'.$name;

        $this->warnUnknownKeys($data, self::TABLE_KEYS, $path);

        $model = null;

        if (array_key_exists('model', $data) && $data['model'] !== null && $data['model'] !== '') {
            if (is_string($data['model'])) {
                $model = trim($data['model']);
            } else {
                $this->error($path.'.model', 'The model must be a class name string.');
            }
        }

        $description = null;

        if (array_key_exists('description', $data) && $data['description'] !== null && $data['description'] !== '') {
            if (is_string($data['description'])) {
                $description = trim($data['description']);
            } else {
                $this->error($path.'.description', 'The description must be a string.');
            }
        }

        $primaryKey = [];

        if (array_key_exists('primary_key', $data)) {
            $primaryKey = $this->normalizeStringList($data['primary_key'], $path.'.primary_key');

            foreach ($primaryKey as $column) {
                if (trim($column) === '') {
                    $this->error($path.'.primary_key', 'Primary key columns must have a name.');
                }
            }

            $primaryKey = array_values(array_filter(
                $primaryKey,
                static fn (string $column): bool => trim($column) !== '',
            ));
            $primaryKey = array_values(array_unique($primaryKey));
        } else {
            $this->warning($path.'.primary_key', 'The table does not declare a primary key.');
        }

        $pivot = false;

        if (array_key_exists('pivot', $data)) {
            $pivot = $this->normalizeBool($data['pivot'], $path.'.pivot');
        }

        $fields = [];
        $rawFields = $data['fields'] ?? [];

        if (!is_array($rawFields)) {
            $this->error($path.'.fields', 'Fields must be an object keyed by column name.');
            $rawFields = [];
        } elseif ($rawFields !== [] && array_is_list($rawFields)) {
            $this->error($path.'.fields', 'Fields must be keyed by column name, not stored in a list.');
            $rawFields = [];
        }

        foreach ($rawFields as $fieldName => $field) {
            if (!is_string($fieldName) || $fieldName === '') {
                $this->error($path.'.fields', 'Every field needs a non-empty name.');
                continue;
            }

            if (!is_array($field)) {
                $this->error($path.'.fields.'.$fieldName, 'The field definition must be an object.');
                continue;
            }

            $fields[$fieldName] = $this->normalizeField($path.'.fields.'.$fieldName, $field);
        }

        if ($fields === []) {
            $this->error($path.'.fields', 'The table needs at least one field.');
        }

        $indexes = [];
        $rawIndexes = $data['indexes'] ?? [];

        if (!is_array($rawIndexes)) {
            $this->error($path.'.indexes', 'Indexes must be a list.');
        } else {
            foreach ($rawIndexes as $position => $index) {
                $indexPath = $path.'.indexes.'.$position;

                if (!is_array($index)) {
                    $this->error($indexPath, 'The index definition must be an object.');
                    continue;
                }

                $normalized = $this->normalizeIndex($indexPath, $index);

                if ($normalized !== null) {
                    $indexes[] = $normalized;
                }
            }
        }

        $table = [];

        if ($model !== null) {
            $table['model'] = $model;
        }

        if ($description !== null) {
            $table['description'] = $description;
        }

        $table['primary_key'] = $primaryKey;

        if ($pivot) {
            $table['pivot'] = true;
        }

        $table['fields'] = $fields;

        if ($indexes !== []) {
            $table['indexes'] = $indexes;
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeField(string $path, array $data): array
    {
        $this->warnUnknownKeys($data, self::FIELD_KEYS, $path);

        $type = 'string';

        if (!array_key_exists('type', $data) || !is_string($data['type']) || trim($data['type']) === '') {
            $this->error($path.'.type', 'Every field needs a type.');
        } else {
            $type = strtolower(trim($data['type']));
        }

        $field = [
            'type' => $type,
        ];

        $length = $this->normalizePositiveInteger($data['length'] ?? null, $path.'.length');

        if ($length !== null) {
            $field['length'] = $length;
        }

        $precision = $this->normalizePositiveInteger($data['precision'] ?? null, $path.'.precision');

        if ($precision !== null) {
            $field['precision'] = $precision;
        }

        $scale = $this->normalizePositiveInteger($data['scale'] ?? null, $path.'.scale', true);

        if ($scale !== null) {
            $field['scale'] = $scale;
        }

        foreach (['nullable', 'unique', 'unsigned', 'auto_increment'] as $boolean) {
            if (array_key_exists($boolean, $data)) {
                $value = $this->normalizeBool($data[$boolean], $path.'.'.$boolean);

                if ($value) {
                    $field[$boolean] = true;
                }
            }
        }

        if (array_key_exists('default', $data)) {
            $default = $data['default'];

            if (is_scalar($default) || $default === null) {
                $field['default'] = $default;
            } else {
                $this->error($path.'.default', 'The default value must be a scalar value or null.');
            }
        }

        if (array_key_exists('references', $data) && $data['references'] !== null) {
            if (!is_array($data['references'])) {
                $this->error($path.'.references', 'The reference must be an object.');
            } else {
                $field['references'] = $this->normalizeReference($path.'.references', $data['references']);
            }
        }

        if (array_key_exists('values', $data)) {
            $values = $this->normalizeStringList($data['values'], $path.'.values');

            if ($values === []) {
                $this->error($path.'.values', 'Enum and set fields need at least one value.');
            } else {
                $field['values'] = $values;
            }
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function normalizeReference(string $path, array $data): array
    {
        $this->warnUnknownKeys($data, self::REFERENCE_KEYS, $path);

        $table = '';

        if (!array_key_exists('table', $data) || !is_string($data['table']) || trim($data['table']) === '') {
            $this->error($path.'.table', 'The reference needs a target table.');
        } else {
            $table = trim($data['table']);
        }

        $field = '';

        if (!array_key_exists('field', $data) || !is_string($data['field']) || trim($data['field']) === '') {
            $this->error($path.'.field', 'The reference needs a target field.');
        } else {
            $field = trim($data['field']);
        }

        $reference = [
            'table' => $table,
            'field' => $field,
        ];

        foreach (['on_delete', 'on_update'] as $action) {
            if (!array_key_exists($action, $data) || $data[$action] === null || $data[$action] === '') {
                continue;
            }

            $value = is_string($data[$action]) ? strtolower(trim($data[$action])) : '';

            if (!in_array($value, ['cascade', 'restrict', 'set_null', 'no_action', 'set_default'], true)) {
                $this->error(
                    $path.'.'.$action,
                    'Use one of: cascade, restrict, set_null, no_action, set_default.',
                );
                continue;
            }

            $reference[$action] = $value;
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function normalizeIndex(string $path, array $data): ?array
    {
        $this->warnUnknownKeys($data, self::INDEX_KEYS, $path);

        $columns = $this->normalizeStringList($data['columns'] ?? [], $path.'.columns');

        if ($columns === []) {
            $this->error($path.'.columns', 'The index needs at least one column.');
            return null;
        }

        $index = [
            'columns' => $columns,
        ];

        if (array_key_exists('name', $data) && $data['name'] !== null && $data['name'] !== '') {
            if (is_string($data['name'])) {
                $index['name'] = trim($data['name']);
            } else {
                $this->error($path.'.name', 'The index name must be a string.');
            }
        }

        if (array_key_exists('unique', $data)) {
            $unique = $this->normalizeBool($data['unique'], $path.'.unique');

            if ($unique) {
                $index['unique'] = true;
            }
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value, string $path): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            $this->error($path, 'Expected a list of strings.');
            return [];
        }

        $result = [];

        foreach ($value as $position => $item) {
            if (!is_string($item)) {
                if (is_int($item) || is_float($item)) {
                    $result[] = (string) $item;
                    continue;
                }

                $this->error($path.'.'.$position, 'Every value must be a string.');
                continue;
            }

            $result[] = trim($item);
        }

        return array_values(array_filter(
            $result,
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function normalizeString(mixed $value, string $path, ?string $default = null): ?string
    {
        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            $this->error($path, 'Expected a string.');
            return $default;
        }

        return $value;
    }

    private function normalizeInteger(mixed $value, string $path, ?int $default = null): ?int
    {
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && (float) (int) $value === $value) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        $this->error($path, 'Expected an integer.');
        return $default;
    }

    private function normalizePositiveInteger(mixed $value, string $path, bool $allowZero = false): ?int
    {
        if ($value === null) {
            return null;
        }

        $integer = $this->normalizeInteger($value, $path);

        if ($integer === null) {
            return null;
        }

        if ($integer < 0 || (! $allowZero && $integer === 0)) {
            $this->error($path, $allowZero ? 'Expected a positive integer or zero.' : 'Expected a positive integer.');
            return null;
        }

        return $integer;
    }

    private function normalizeBool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['true', '1'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0'], true)) {
                return false;
            }
        }

        $this->error($path, 'Expected a boolean.');
        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function warnUnknownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || in_array($key, $allowed, true)) {
                continue;
            }

            $this->warning($path.'.'.$key, 'Unknown property "'.$key.'" will be ignored.');
        }
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
