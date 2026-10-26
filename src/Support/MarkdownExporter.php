<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Relation;
use Huzzidiel\Dber\Data\Table;

/**
 * Genera el contexto de esquema orientado a agentes de IA.
 *
 * El resultado se deriva del documento; no se guarda dentro del archivo. Así
 * la fuente de verdad sigue siendo única y el contexto siempre está fresco.
 */
final class MarkdownExporter
{
    public function export(CompiledDiagram $compiled, ?string $source = null): string
    {
        $diagram = $compiled->diagram;
        $lines = [];

        $lines[] = '# Database schema'.($diagram->name !== '' ? ': '.$diagram->name : '');
        $lines[] = '';

        if ($source !== null) {
            $lines[] = 'Source: `'.$source.'`';
            $lines[] = '';
        }

        $lines[] = 'Tables: '.count($diagram->tables)
            .' · Fields: '.$diagram->fieldCount()
            .' · Relations: '.count($compiled->relations)
            .' · Pivot tables: '.count($compiled->pivotTables);
        $lines[] = '';

        if ($diagram->database !== null) {
            $lines[] = 'Database engine: `'.$diagram->database.'`.';
            $lines[] = '';
        }

        $lines = array_merge($lines, $this->editingGuide());

        $lines[] = '## Tables';
        $lines[] = '';

        if ($diagram->tables === []) {
            $lines[] = '_No tables are defined yet._';
            $lines[] = '';
        }

        foreach ($diagram->tables as $table) {
            $lines = array_merge($lines, $this->tableSection($table));

            if (in_array($table->name, $compiled->pivotTables, true)) {
                $lines[] = 'Pivot table: `true`.';
                $lines[] = '';
            }
        }

        $lines[] = '## Relationships';
        $lines[] = '';
        $lines = array_merge($lines, $this->relationshipSection($compiled->relations));

        $lines[] = '## Type reference';
        $lines[] = '';
        $lines[] = '| Schema type | Laravel migration method | Notes |';
        $lines[] = '| --- | --- | --- |';

        foreach (TypeRegistry::TYPES as $type) {
            $notes = [];

            if (TypeRegistry::supportsLength($type)) {
                $notes[] = 'uses `length`';
            }

            if (TypeRegistry::supportsPrecision($type)) {
                $notes[] = 'uses `precision`/`scale`';
            }

            if (TypeRegistry::supportsValues($type)) {
                $notes[] = 'uses `values`';
            }

            if (TypeRegistry::supportsUnsigned($type)) {
                $notes[] = 'supports `unsigned`';
            }

            $lines[] = '| `'.$type.'` | `'.TypeRegistry::laravelMethod($type).'` | '
                .($notes === [] ? '—' : implode(', ', $notes)).' |';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Reglas globales de lectura y edición del archivo.
     *
     * @return list<string>
     */
    private function editingGuide(): array
    {
        return [
            '## How to read and edit this schema',
            '',
            'This document is the single source of truth for the database diagram. '
            .'Update the JSON/YAML file, never a generated artifact.',
            '',
            '- `tables` is an object keyed by the physical table name.',
            '- `fields` is an object keyed by the physical column name.',
            '- `primary_key` is an array so composite keys are explicit.',
            '- A foreign key is declared exactly once, on the referencing field, '
            .'inside `references`. Do not add the inverse relationship.',
            '- `tables.<name>.pivot = true` marks a many-to-many join table. '
            .'It must contain at least two foreign keys.',
            '- Field types use the canonical names in the type reference below. '
            .'Use `length` for strings, `precision`/`scale` for decimals and '
            .'`values` for enums.',
            '- Timestamps and soft-delete columns are explicit fields; there are no hidden conventions.',
            '- Table and column names use `snake_case`.',
            '- After editing, run `php artisan dber:validate`.',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function tableSection(Table $table): array
    {
        $lines = [];
        $lines[] = '### `'.$table->name.'`';
        $lines[] = '';

        if ($table->description !== null) {
            $lines[] = $table->description;
            $lines[] = '';
        }

        if ($table->model !== null) {
            $lines[] = 'Model: `'.$table->model.'`';
            $lines[] = '';
        }

        $primaryKey = $table->primaryKey === []
            ? '_none_'
            : implode(', ', array_map(
                static fn (string $column): string => '`'.$column.'`',
                $table->primaryKey,
            ));

        $lines[] = 'Primary key: '.$primaryKey;
        $lines[] = '';

        if ($table->fields === []) {
            $lines[] = '_No fields._';
            $lines[] = '';
            return $lines;
        }

        $lines[] = '| Field | Type | Null | Default | Key | References |';
        $lines[] = '| --- | --- | --- | --- | --- | --- |';

        foreach ($table->fields as $field) {
            $keys = [];

            if ($table->isPrimaryKey($field->name)) {
                $keys[] = 'PK';
            }

            if ($field->references !== null) {
                $keys[] = 'FK';
            }

            if ($field->unique) {
                $keys[] = 'UQ';
            }

            $reference = $field->references === null
                ? '—'
                : '`'.$field->references->table.'.'.$field->references->field.'`'
                    .($field->references->onDelete !== null
                        ? ' (on delete '.$field->references->onDelete.')'
                        : '');

            $lines[] = '| `'.$this->cell($field->name).'`'
                .' | `'.$this->cell($field->typeLabel()).'`'
                .' | '.($field->nullable ? 'yes' : 'no')
                .' | '.$this->defaultLabel($field)
                .' | '.($keys === [] ? '—' : implode(', ', $keys))
                .' | '.$reference.' |';
        }

        $lines[] = '';

        if ($table->indexes !== []) {
            $lines[] = 'Indexes:';

            foreach ($table->indexes as $index) {
                $columns = implode(', ', array_map(
                    static fn (string $column): string => '`'.$column.'`',
                    $index->columns,
                ));
                $label = $index->unique ? 'unique' : 'index';
                $name = $index->name !== null ? ' `'.$index->name.'`' : '';

                $lines[] = '- '.$label.' '.$name.' on '.$columns;
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param list<Relation> $relations
     * @return list<string>
     */
    private function relationshipSection(array $relations): array
    {
        if ($relations === []) {
            return [
                '_No relationships are defined yet._',
                '',
            ];
        }

        $lines = [];

        foreach ($relations as $relation) {
            if ($relation->kind === Relation::MANY_TO_MANY) {
                $lines[] = '- `'.$relation->fromTable.'` ↔ `'.$relation->toTable
                    .'` — many-to-many via `'.$relation->pivotTable
                    .'` (`'.$relation->fromField.'`, `'.$relation->toField.'`).';
                continue;
            }

            $flags = [$relation->kind];

            if ($relation->nullable) {
                $flags[] = 'nullable';
            }

            if ($relation->onDelete !== null) {
                $flags[] = 'on delete '.$relation->onDelete;
            }

            if ($relation->onUpdate !== null) {
                $flags[] = 'on update '.$relation->onUpdate;
            }

            $lines[] = '- `'.$relation->fromTable.'.'.$relation->fromField.'` → `'
                .$relation->toTable.'.'.$relation->toField.'` — '.implode(', ', $flags).'.';
        }

        $lines[] = '';

        return $lines;
    }

    private function defaultLabel(Field $field): string
    {
        if (! $field->hasDefault) {
            return '—';
        }

        if ($field->default === null) {
            return '`null`';
        }

        if (is_bool($field->default)) {
            return $field->default ? '`true`' : '`false`';
        }

        if (is_string($field->default)) {
            return '`\''.$this->cell($field->default).'\'`';
        }

        return '`'.$this->cell((string) $field->default).'`';
    }

    private function cell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
