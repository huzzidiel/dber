<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Tabla del esquema.
 *
 * Las relaciones se derivan de las referencias de sus columnas; no se
 * almacenan duplicadas en el archivo.
 */
final readonly class Table
{
    /**
     * @param list<string> $primaryKey
     * @param array<string, Field> $fields
     * @param list<IndexDefinition> $indexes
     */
    public function __construct(
        public string $name,
        public ?string $model = null,
        public ?string $description = null,
        public array $primaryKey = [],
        public bool $pivot = false,
        public array $fields = [],
        public array $indexes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $fields = [];

        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $fieldName => $field) {
                if (is_string($fieldName) && is_array($field)) {
                    $fields[$fieldName] = Field::fromArray($fieldName, $field);
                }
            }
        }

        $primaryKey = $data['primary_key'] ?? [];

        if (is_string($primaryKey)) {
            $primaryKey = [$primaryKey];
        }

        $indexes = [];

        if (isset($data['indexes']) && is_array($data['indexes'])) {
            foreach ($data['indexes'] as $index) {
                if (is_array($index)) {
                    $indexes[] = IndexDefinition::fromArray($index);
                }
            }
        }

        return new self(
            name: $name,
            model: isset($data['model']) && $data['model'] !== '' ? (string) $data['model'] : null,
            description: isset($data['description']) && $data['description'] !== ''
                ? (string) $data['description']
                : null,
            primaryKey: is_array($primaryKey)
                ? array_values(array_map(static fn (mixed $column): string => (string) $column, $primaryKey))
                : [],
            pivot: (bool) ($data['pivot'] ?? false),
            fields: $fields,
            indexes: $indexes,
        );
    }

    /**
     * Devuelve una columna por nombre.
     */
    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Indica si la columna pertenece a la clave primaria.
     */
    public function isPrimaryKey(string $field): bool
    {
        return in_array($field, $this->primaryKey, true);
    }

    /**
     * Columnas que declaran una referencia foránea.
     *
     * @return array<string, Field>
     */
    public function foreignKeys(): array
    {
        return array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->references !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->model !== null) {
            $data['model'] = $this->model;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        $data['primary_key'] = $this->primaryKey;

        if ($this->pivot) {
            $data['pivot'] = true;
        }

        $fields = [];

        foreach ($this->fields as $field) {
            $fields[$field->name] = $field->toArray();
        }

        $data['fields'] = $fields;

        if ($this->indexes !== []) {
            $data['indexes'] = array_map(
                static fn (IndexDefinition $index): array => $index->toArray(),
                $this->indexes,
            );
        }

        return $data;
    }
}
