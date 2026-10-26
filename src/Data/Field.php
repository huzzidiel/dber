<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

use Huzzidiel\Dber\Support\TypeRegistry;

/**
 * Columna de una tabla.
 *
 * `hasDefault` permite distinguir entre "sin default" y un default null
 * explícito.
 */
final readonly class Field
{
    /**
     * @param list<string> $values
     * @param string|int|float|bool|null $default
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = false,
        public bool $unique = false,
        public bool $unsigned = false,
        public bool $autoIncrement = false,
        public bool $hasDefault = false,
        public string|int|float|bool|null $default = null,
        public ?Reference $references = null,
        public array $values = [],
    ) {
    }

    /**
     * Construye la columna desde su representación normalizada.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $default = $data['default'] ?? null;

        if (!is_scalar($default) && $default !== null) {
            $default = null;
        }

        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'string'),
            length: isset($data['length']) ? (int) $data['length'] : null,
            precision: isset($data['precision']) ? (int) $data['precision'] : null,
            scale: isset($data['scale']) ? (int) $data['scale'] : null,
            nullable: (bool) ($data['nullable'] ?? false),
            unique: (bool) ($data['unique'] ?? false),
            unsigned: (bool) ($data['unsigned'] ?? false),
            autoIncrement: (bool) ($data['auto_increment'] ?? false),
            hasDefault: array_key_exists('default', $data),
            default: $default,
            references: isset($data['references']) && is_array($data['references'])
                ? Reference::fromArray($data['references'])
                : null,
            values: isset($data['values']) && is_array($data['values'])
                ? array_values(array_map(static fn (mixed $value): string => (string) $value, $data['values']))
                : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
        ];

        if ($this->length !== null) {
            $data['length'] = $this->length;
        }

        if ($this->precision !== null) {
            $data['precision'] = $this->precision;
        }

        if ($this->scale !== null) {
            $data['scale'] = $this->scale;
        }

        if ($this->nullable) {
            $data['nullable'] = true;
        }

        if ($this->unique) {
            $data['unique'] = true;
        }

        if ($this->unsigned) {
            $data['unsigned'] = true;
        }

        if ($this->autoIncrement) {
            $data['auto_increment'] = true;
        }

        if ($this->hasDefault) {
            $data['default'] = $this->default;
        }

        if ($this->references !== null) {
            $data['references'] = $this->references->toArray();
        }

        if ($this->values !== []) {
            $data['values'] = $this->values;
        }

        return $data;
    }

    /**
     * Representación compacta del tipo, por ejemplo string(255) o decimal(10,2).
     */
    public function typeLabel(): string
    {
        if ($this->values !== [] && TypeRegistry::supportsValues($this->type)) {
            return $this->type.'('.implode(',', $this->values).')';
        }

        if ($this->precision !== null) {
            $suffix = $this->scale !== null
                ? $this->precision.','.$this->scale
                : (string) $this->precision;

            return $this->type.'('.$suffix.')';
        }

        if ($this->length !== null) {
            return $this->type.'('.$this->length.')';
        }

        return $this->type;
    }
}
