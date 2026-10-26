<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Relación derivada entre dos tablas.
 *
 * `from` es la tabla que contiene la clave foránea (o el lado izquierdo de una
 * relación muchos-a-muchos); `to` es la tabla referenciada.
 */
final readonly class Relation
{
    public const MANY_TO_ONE = 'many-to-one';

    public const ONE_TO_ONE = 'one-to-one';

    public const MANY_TO_MANY = 'many-to-many';

    public function __construct(
        public string $id,
        public string $kind,
        public string $fromTable,
        public ?string $fromField,
        public string $toTable,
        public ?string $toField,
        public bool $nullable = false,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public ?string $pivotTable = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'from_table' => $this->fromTable,
            'from_field' => $this->fromField,
            'to_table' => $this->toTable,
            'to_field' => $this->toField,
            'nullable' => $this->nullable,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
            'pivot_table' => $this->pivotTable,
        ];
    }
}
