<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Illuminate\Support\Str;

/**
 * Construye el esquema intermedio aplicando las operaciones de las migraciones
 * en el orden en que aparecen.
 */
final class MigrationSchemaBuilder
{
    /**
     * @var array<string, array{fields: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>, primary_key: list<string>, pivot: bool}>
     */
    private array $tables = [];

    /**
     * @var array{table: string, field: string}|null
     */
    private ?array $current = null;

    /**
     * @var array{table: string, columns: list<string>, column: string|null}|null
     */
    private ?array $pendingForeign = null;

    /**
     * @var array{table: string, field: string}|null
     */
    private ?array $lastForeign = null;

    /**
     * @var list<array{file: string|null, message: string}>
     */
    private array $warnings = [];

    /**
     * @param list<array{op: string, table: string, new_table: string|null, body: string|null, file: string|null}> $operations
     * @return array<string, array{fields: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>, primary_key: list<string>, pivot: bool}>
     */
    public function build(array $operations, MigrationFileParser $parser): array
    {
        $this->tables = [];
        $this->current = null;
        $this->pendingForeign = null;
        $this->lastForeign = null;
        $this->warnings = [];

        foreach ($operations as $operation) {
            $this->applyOperation($operation, $parser);
        }

        foreach (array_keys($this->tables) as $table) {
            $this->detectPivot($table);
        }

        return $this->tables;
    }

    /**
     * @return list<array{file: string|null, message: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array{op: string, table: string, new_table: string|null, body: string|null, file: string|null} $operation
     */
    private function applyOperation(array $operation, MigrationFileParser $parser): void
    {
        $table = $operation['table'];

        match ($operation['op']) {
            'create' => $this->ensureTable($table),
            'table' => $this->ensureTable($table),
            'drop' => $this->dropTable($table),
            'rename' => $this->renameTable($table, $operation['new_table'] ?? ''),
            default => null,
        };

        if ($operation['op'] === 'drop') {
            return;
        }

        if ($operation['op'] === 'rename') {
            return;
        }

        $body = $operation['body'];

        if ($body === null || $body === '') {
            return;
        }

        foreach ($parser->parseBlueprintBody($body) as $calls) {
            foreach ($calls as $call) {
                $this->applyMethod($table, $call['method'], $call['args'], $operation['file'] ?? null);
            }
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function applyMethod(string $table, string $method, array $args, ?string $file): void
    {
        $name = isset($args[0]) && is_string($args[0]) ? $args[0] : null;

        match ($method) {
            // Claves primarias autoincrementales.
            'id', 'bigIncrements' => $this->addIncrement($table, $name ?? 'id', 'bigint'),
            'increments' => $this->addIncrement($table, $name ?? 'id', 'integer'),
            'mediumIncrements' => $this->addIncrement($table, $name ?? 'id', 'mediumint'),
            'smallIncrements' => $this->addIncrement($table, $name ?? 'id', 'smallint'),
            'tinyIncrements' => $this->addIncrement($table, $name ?? 'id', 'tinyint'),

            // Enteros.
            'bigInteger' => $this->addColumn($table, $name, ['type' => 'bigint']),
            'integer' => $this->addColumn($table, $name, ['type' => 'integer']),
            'mediumInteger' => $this->addColumn($table, $name, ['type' => 'mediumint']),
            'smallInteger' => $this->addColumn($table, $name, ['type' => 'smallint']),
            'tinyInteger' => $this->addColumn($table, $name, ['type' => 'tinyint']),
            'unsignedBigInteger' => $this->addColumn($table, $name, ['type' => 'bigint', 'unsigned' => true]),
            'unsignedInteger' => $this->addColumn($table, $name, ['type' => 'integer', 'unsigned' => true]),
            'unsignedMediumInteger' => $this->addColumn($table, $name, ['type' => 'mediumint', 'unsigned' => true]),
            'unsignedSmallInteger' => $this->addColumn($table, $name, ['type' => 'smallint', 'unsigned' => true]),
            'unsignedTinyInteger' => $this->addColumn($table, $name, ['type' => 'tinyint', 'unsigned' => true]),

            // Textos.
            'string' => $this->addColumn($table, $name, ['type' => 'string', 'length' => (int) ($args[1] ?? 255)]),
            'char' => $this->addColumn($table, $name, $this->lengthData('char', $args)),
            'text' => $this->addColumn($table, $name, ['type' => 'text']),
            'tinyText' => $this->addColumn($table, $name, ['type' => 'text']),
            'mediumText' => $this->addColumn($table, $name, ['type' => 'mediumtext']),
            'longText' => $this->addColumn($table, $name, ['type' => 'longtext']),
            'binary' => $this->addColumn($table, $name, $this->lengthData('binary', $args)),

            // Numéricos y fechas.
            'boolean' => $this->addColumn($table, $name, ['type' => 'boolean']),
            'decimal' => $this->addColumn($table, $name, $this->decimalData('decimal', $args)),
            'unsignedDecimal' => $this->addColumn($table, $name, $this->decimalData('decimal', $args, true)),
            'float' => $this->addColumn($table, $name, $this->decimalData('float', $args)),
            'unsignedFloat' => $this->addColumn($table, $name, $this->decimalData('float', $args, true)),
            'double' => $this->addColumn($table, $name, $this->decimalData('double', $args)),
            'unsignedDouble' => $this->addColumn($table, $name, $this->decimalData('double', $args, true)),
            'date' => $this->addColumn($table, $name, ['type' => 'date']),
            'dateTime', 'dateTimeTz' => $this->addColumn($table, $name, ['type' => 'datetime']),
            'timestamp', 'timestampTz' => $this->addColumn($table, $name, ['type' => 'timestamp']),
            'time', 'timeTz' => $this->addColumn($table, $name, ['type' => 'time']),
            'year' => $this->addColumn($table, $name, ['type' => 'year']),
            'json', 'jsonb' => $this->addColumn($table, $name, ['type' => 'json']),
            'uuid' => $this->addColumn($table, $name, ['type' => 'uuid']),
            'ulid' => $this->addColumn($table, $name, ['type' => 'ulid']),
            'enum' => $this->addColumn($table, $name, ['type' => 'enum', 'values' => $this->values($args)]),
            'set' => $this->addColumn($table, $name, ['type' => 'set', 'values' => $this->values($args)]),

            // Claves foráneas.
            'foreignId' => $this->addColumn($table, $name, ['type' => 'bigint', 'unsigned' => true]),
            'foreignUuid' => $this->addColumn($table, $name, ['type' => 'uuid']),
            'foreignUlid' => $this->addColumn($table, $name, ['type' => 'ulid']),

            // Atajos.
            'rememberToken' => $this->addColumn($table, 'remember_token', ['type' => 'string', 'length' => 100, 'nullable' => true]),
            'timestamps', 'nullableTimestamps', 'timestampsTz', 'nullableTimestampsTz' => $this->addTimestamps($table),
            'softDeletes', 'softDeletesTz', 'softDeletesDatetime' => $this->addColumn($table, 'deleted_at', ['type' => 'timestamp', 'nullable' => true]),
            'dropSoftDeletes' => $this->dropColumn($table, ['deleted_at']),
            'morphs' => $this->addMorphs($table, $name ?? 'morph', false, 'bigint'),
            'nullableMorphs' => $this->addMorphs($table, $name ?? 'morph', true, 'bigint'),
            'uuidMorphs' => $this->addMorphs($table, $name ?? 'morph', false, 'uuid'),
            'nullableUuidMorphs' => $this->addMorphs($table, $name ?? 'morph', true, 'uuid'),
            'ulidMorphs' => $this->addMorphs($table, $name ?? 'morph', false, 'ulid'),
            'nullableUlidMorphs' => $this->addMorphs($table, $name ?? 'morph', true, 'ulid'),

            // Modificadores.
            'nullable' => $this->modifyCurrent($table, 'nullable', true),
            'unsigned' => $this->modifyCurrent($table, 'unsigned', true),
            'default' => $this->modifyCurrent($table, 'default', $this->normalizeDefault($args[0] ?? null)),
            'length' => $this->modifyCurrent($table, 'length', (int) ($args[0] ?? 0)),
            'precision' => $this->modifyCurrent($table, 'precision', (int) ($args[0] ?? 0)),
            'scale' => $this->modifyCurrent($table, 'scale', (int) ($args[0] ?? 0)),
            'autoIncrement' => $this->modifyCurrent($table, 'auto_increment', true),
            'unique' => $this->addIndex($table, $this->singleColumnList($args), true),
            'index' => $this->addIndex($table, $this->singleColumnList($args), false),
            'primary' => $this->setPrimary($table, $this->singleColumnList($args)),
            'foreign' => $this->beginForeign($table, $this->variadicColumnList($args)),
            'references' => $this->pendingForeignColumn($args[0] ?? 'id'),
            'on' => $this->applyForeign($args[0] ?? ''),
            'constrained' => $this->applyConstrained($table, $args),
            'cascadeOnDelete' => $this->referenceAction('on_delete', 'cascade'),
            'restrictOnDelete' => $this->referenceAction('on_delete', 'restrict'),
            'nullOnDelete' => $this->referenceAction('on_delete', 'set_null'),
            'noActionOnDelete' => $this->referenceAction('on_delete', 'no_action'),
            'cascadeOnUpdate' => $this->referenceAction('on_update', 'cascade'),
            'restrictOnUpdate' => $this->referenceAction('on_update', 'restrict'),
            'nullOnUpdate' => $this->referenceAction('on_update', 'set_null'),
            'onDelete' => $this->referenceAction('on_delete', $args[0] ?? ''),
            'onUpdate' => $this->referenceAction('on_update', $args[0] ?? ''),
            'renameColumn' => $this->renameColumn($table, $args[0] ?? '', $args[1] ?? ''),
            'dropColumn' => $this->dropColumn($table, $this->variadicColumnList($args)),
            'dropForeign' => $this->warn($file, 'Skipped dropForeign (unmapped index name).'),

            // Ignorados.
            'charset', 'collation', 'comment', 'first', 'after', 'from',
            'useCurrent', 'useCurrentOnUpdate', 'storedAs', 'virtualAs',
            'always', 'generatedAs', 'invisible', 'fullText', 'spatialIndex',
            'rawIndex', 'dropPrimary' => null,

            default => $this->warn($file, 'Skipped unsupported method: '.$method.'().'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function lengthData(string $type, array $args): array
    {
        $data = [
            'type' => $type,
        ];

        if (isset($args[1])) {
            $data['length'] = (int) $args[1];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function decimalData(string $type, array $args, bool $unsigned = false): array
    {
        $data = [
            'type' => $type,
            'precision' => (int) ($args[1] ?? 8),
            'scale' => (int) ($args[2] ?? 2),
        ];

        if ($unsigned) {
            $data['unsigned'] = true;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function values(array $args): array
    {
        if (! isset($args[1]) || ! is_array($args[1])) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $args[1]));
    }

    private function normalizeDefault(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function addColumn(string $table, ?string $name, array $data): void
    {
        if ($name === null) {
            $this->warn(null, 'Skipped a column without a name.');
            return;
        }

        $name = MigrationFileParser::resolveName($name);

        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            $this->warn(null, 'Skipped dynamic column name: '.$name);
            return;
        }

        $this->ensureTable($table);
        $this->tables[$table]['fields'][$name] = $data;
        $this->current = [
            'table' => $table,
            'field' => $name,
        ];
    }

    private function addIncrement(string $table, string $name, string $type): void
    {
        $this->addColumn($table, $name, [
            'type' => $type,
            'unsigned' => true,
            'auto_increment' => true,
        ]);

        if (! in_array($name, $this->tables[$table]['primary_key'], true)) {
            $this->tables[$table]['primary_key'][] = $name;
        }
    }

    private function addTimestamps(string $table): void
    {
        $this->addColumn($table, 'created_at', ['type' => 'timestamp', 'nullable' => true]);
        $this->addColumn($table, 'updated_at', ['type' => 'timestamp', 'nullable' => true]);
    }

    private function addMorphs(string $table, string $name, bool $nullable, string $idType): void
    {
        $typeData = [
            'type' => $idType === 'uuid' || $idType === 'ulid' ? $idType : 'string',
        ];

        if ($typeData['type'] === 'string') {
            $typeData['length'] = 255;
        }

        $idData = [
            'type' => $idType,
        ];

        if ($idType === 'bigint') {
            $idData['unsigned'] = true;
        }

        if ($nullable) {
            $typeData['nullable'] = true;
            $idData['nullable'] = true;
        }

        $this->addColumn($table, $name.'_type', $typeData);
        $this->addColumn($table, $name.'_id', $idData);
        $this->addIndex($table, [$name.'_type', $name.'_id'], false);
    }

    private function modifyCurrent(string $table, string $key, mixed $value): void
    {
        if ($this->current === null || $this->current['table'] !== $table) {
            $this->warn(null, 'Skipped a modifier without a column.');
            return;
        }

        $field = $this->current['field'];

        if (! isset($this->tables[$table]['fields'][$field])) {
            return;
        }

        $this->tables[$table]['fields'][$field][$key] = $value;
    }

    /**
     * @param list<string> $columns
     */
    private function addIndex(string $table, array $columns, bool $unique): void
    {
        if ($columns === []) {
            return;
        }

        $this->ensureTable($table);

        foreach ($columns as $column) {
            if (! isset($this->tables[$table]['fields'][$column])) {
                $this->warn(null, 'Skipped index referencing an unknown column: '.$column);
                return;
            }
        }

        if ($unique && count($columns) === 1) {
            $field = $columns[0];
            $this->tables[$table]['fields'][$field]['unique'] = true;
        }

        $this->tables[$table]['indexes'][] = [
            'columns' => $columns,
            'unique' => $unique,
        ];
    }

    /**
     * @param list<string> $columns
     */
    private function setPrimary(string $table, array $columns): void
    {
        $this->ensureTable($table);

        foreach ($columns as $column) {
            if (! isset($this->tables[$table]['fields'][$column])) {
                $this->warn(null, 'Skipped primary key referencing an unknown column: '.$column);
                return;
            }
        }

        $this->tables[$table]['primary_key'] = $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function beginForeign(string $table, array $columns): void
    {
        $this->pendingForeign = [
            'table' => $table,
            'columns' => $columns,
            'column' => null,
        ];
    }

    private function pendingForeignColumn(mixed $column): void
    {
        if ($this->pendingForeign === null) {
            return;
        }

        $this->pendingForeign['column'] = (string) $column;
    }

    private function applyForeign(mixed $table): void
    {
        if ($this->pendingForeign === null || $this->pendingForeign['column'] === null) {
            $this->pendingForeign = null;
            return;
        }

        $columns = $this->pendingForeign['columns'];

        if (count($columns) !== 1) {
            $this->warn(null, 'Skipped a composite foreign key.');
            $this->pendingForeign = null;
            return;
        }

        $field = $columns[0];

        $this->applyForeignKey(
            $this->pendingForeign['table'],
            $field,
            MigrationFileParser::resolveName((string) $table),
            $this->pendingForeign['column'],
        );

        $this->pendingForeign = null;
    }

    /**
     * @param list<mixed> $args
     */
    private function applyConstrained(string $table, array $args): void
    {
        if ($this->current === null || $this->current['table'] !== $table) {
            $this->warn(null, 'Skipped constrained() without a column.');
            return;
        }

        $field = $this->current['field'];
        $targetTable = isset($args[0]) && is_string($args[0])
            ? MigrationFileParser::resolveName($args[0])
            : $this->guessTable($field);
        $targetColumn = isset($args[1]) ? (string) $args[1] : 'id';

        $this->applyForeignKey($table, $field, $targetTable, $targetColumn);
    }

    private function applyForeignKey(string $table, string $field, string $targetTable, string $targetColumn): void
    {
        if (! isset($this->tables[$table]['fields'][$field])) {
            return;
        }

        $this->tables[$table]['fields'][$field]['references'] = [
            'table' => $targetTable,
            'field' => $targetColumn,
        ];

        $this->lastForeign = [
            'table' => $table,
            'field' => $field,
        ];
    }

    private function referenceAction(string $action, mixed $value): void
    {
        $value = (string) $value;

        if ($this->lastForeign === null || $value === '') {
            return;
        }

        $table = $this->lastForeign['table'];
        $field = $this->lastForeign['field'];

        if (! isset($this->tables[$table]['fields'][$field]['references'])) {
            return;
        }

        $this->tables[$table]['fields'][$field]['references'][$action] = $value;
    }

    private function renameColumn(string $table, mixed $from, mixed $to): void
    {
        $from = (string) $from;
        $to = (string) $to;

        if ($from === '' || $to === '' || ! isset($this->tables[$table]['fields'][$from])) {
            return;
        }

        $fields = [];

        foreach ($this->tables[$table]['fields'] as $fieldName => $field) {
            $fields[$fieldName === $from ? $to : $fieldName] = $field;
        }

        $this->tables[$table]['fields'] = $fields;
        $this->tables[$table]['primary_key'] = array_map(
            static fn (string $column): string => $column === $from ? $to : $column,
            $this->tables[$table]['primary_key'],
        );
    }

    /**
     * @param list<string> $columns
     */
    private function dropColumn(string $table, array $columns): void
    {
        if (! isset($this->tables[$table])) {
            return;
        }

        foreach ($columns as $column) {
            unset($this->tables[$table]['fields'][$column]);
        }

        $this->tables[$table]['primary_key'] = array_values(array_filter(
            $this->tables[$table]['primary_key'],
            static fn (string $column): bool => ! in_array($column, $columns, true),
        ));

        $this->tables[$table]['indexes'] = array_values(array_filter(
            $this->tables[$table]['indexes'],
            static fn (array $index): bool => array_diff($index['columns'], $columns) !== [],
        ));
    }

    /**
     * @return list<string>
     */
    private function singleColumnList(array $args): array
    {
        if ($args === []) {
            if ($this->current !== null) {
                return [$this->current['field']];
            }

            return [];
        }

        $first = $args[0];

        if (is_array($first)) {
            $columns = array_map(static fn (mixed $value): string => (string) $value, $first);
        } else {
            $columns = [(string) $first];
        }

        return $this->resolveColumns($columns);
    }

    private function variadicColumnList(array $args): array
    {
        if ($args === []) {
            if ($this->current !== null) {
                return [$this->current['field']];
            }

            return [];
        }

        $first = $args[0];

        if (is_array($first)) {
            $columns = array_map(static fn (mixed $value): string => (string) $value, $first);
        } else {
            $columns = array_map(static fn (mixed $value): string => (string) $value, $args);
        }

        return $this->resolveColumns($columns);
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function resolveColumns(array $columns): array
    {
        return array_values(array_map(
            static fn (string $column): string => MigrationFileParser::resolveName($column),
            $columns,
        ));
    }

    private function ensureTable(string $table): void
    {
        if (! isset($this->tables[$table])) {
            $this->tables[$table] = [
                'fields' => [],
                'indexes' => [],
                'primary_key' => [],
                'pivot' => false,
            ];
        }
    }

    private function dropTable(string $table): void
    {
        unset($this->tables[$table]);
    }

    private function renameTable(string $from, string $to): void
    {
        if ($from === '' || $to === '' || ! isset($this->tables[$from])) {
            return;
        }

        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name === $from ? $to : $name] = $table;
        }

        $this->tables = $tables;
    }

    private function detectPivot(string $table): void
    {
        $fields = $this->tables[$table]['fields'];
        $foreignKeys = [];

        foreach ($fields as $name => $field) {
            if (isset($field['references'])) {
                $foreignKeys[] = $name;
            }
        }

        $primary = $this->tables[$table]['primary_key'];

        if (count($foreignKeys) !== 2 || count($primary) !== 2) {
            return;
        }

        foreach ($primary as $column) {
            if (! in_array($column, $foreignKeys, true)) {
                return;
            }
        }

        foreach ($fields as $name => $field) {
            if (in_array($name, $foreignKeys, true)) {
                continue;
            }

            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            return;
        }

        $this->tables[$table]['pivot'] = true;
    }

    private function guessTable(string $column): string
    {
        $base = Str::beforeLast($column, '_id');

        return Str::plural($base);
    }

    private function warn(?string $file, string $message): void
    {
        $this->warnings[] = [
            'file' => $file,
            'message' => $message,
        ];
    }
}
