<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Índice declarado en una tabla.
 */
final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public array $columns,
        public ?string $name = null,
        public bool $unique = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $columns = $data['columns'] ?? [];

        if (is_string($columns)) {
            $columns = [$columns];
        }

        return new self(
            columns: is_array($columns)
                ? array_values(array_map(static fn (mixed $column): string => (string) $column, $columns))
                : [],
            name: isset($data['name']) ? (string) $data['name'] : null,
            unique: (bool) ($data['unique'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'columns' => $this->columns,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->unique) {
            $data['unique'] = true;
        }

        return $data;
    }
}
