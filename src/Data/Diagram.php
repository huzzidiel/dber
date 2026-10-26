<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Documento de esquema ya interpretado.
 */
final readonly class Diagram
{
    /**
     * @param array<string, Table> $tables
     */
    public function __construct(
        public int $schemaVersion,
        public string $name,
        public ?string $database,
        public array $tables,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tables = [];

        if (isset($data['tables']) && is_array($data['tables'])) {
            foreach ($data['tables'] as $tableName => $table) {
                if (is_string($tableName) && is_array($table)) {
                    $tables[$tableName] = Table::fromArray($tableName, $table);
                }
            }
        }

        return new self(
            schemaVersion: (int) ($data['schema_version'] ?? 1),
            name: (string) ($data['name'] ?? 'Laravel application'),
            database: isset($data['database']) && $data['database'] !== ''
                ? (string) $data['database']
                : null,
            tables: $tables,
        );
    }

    /**
     * Devuelve una tabla por nombre.
     */
    public function table(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * Número total de columnas.
     */
    public function fieldCount(): int
    {
        return array_sum(array_map(
            static fn (Table $table): int => count($table->fields),
            $this->tables,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tables = [];

        foreach ($this->tables as $table) {
            $tables[$table->name] = $table->toArray();
        }

        return [
            'schema_version' => $this->schemaVersion,
            'name' => $this->name,
            'database' => $this->database,
            'tables' => $tables,
        ];
    }
}
