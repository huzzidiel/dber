<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Referencia de una columna hacia la clave de otra tabla.
 */
final readonly class Reference
{
    /**
     * Acciones referenciales soportadas.
     *
     * @var list<string>
     */
    public const ON_ACTIONS = [
        'cascade',
        'restrict',
        'set_null',
        'no_action',
        'set_default',
    ];

    public function __construct(
        public string $table,
        public string $field,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
    ) {
    }

    /**
     * Construye la referencia desde su representación en el archivo.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            table: (string) ($data['table'] ?? ''),
            field: (string) ($data['field'] ?? ''),
            onDelete: isset($data['on_delete']) ? (string) $data['on_delete'] : null,
            onUpdate: isset($data['on_update']) ? (string) $data['on_update'] : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'table' => $this->table,
            'field' => $this->field,
        ];

        if ($this->onDelete !== null) {
            $data['on_delete'] = $this->onDelete;
        }

        if ($this->onUpdate !== null) {
            $data['on_update'] = $this->onUpdate;
        }

        return $data;
    }
}
