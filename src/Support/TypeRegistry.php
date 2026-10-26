<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

/**
 * Catálogo de tipos de columna soportados por el esquema.
 *
 * Centraliza qué modificadores acepta cada tipo y cómo se representa en
 * Laravel, SQL, Markdown y Mermaid.
 */
final class TypeRegistry
{
    /**
     * Tipos canónicos usados en el archivo de esquema.
     *
     * @var list<string>
     */
    public const TYPES = [
        'bigint',
        'integer',
        'mediumint',
        'smallint',
        'tinyint',
        'string',
        'char',
        'text',
        'mediumtext',
        'longtext',
        'boolean',
        'decimal',
        'float',
        'double',
        'date',
        'datetime',
        'timestamp',
        'time',
        'year',
        'json',
        'uuid',
        'ulid',
        'binary',
        'enum',
        'set',
    ];

    /**
     * Tipos que aceptan longitud.
     *
     * @var list<string>
     */
    private const LENGTH_TYPES = [
        'string',
        'char',
        'binary',
    ];

    /**
     * Tipos que aceptan precisión y escala.
     *
     * @var list<string>
     */
    private const PRECISION_TYPES = [
        'decimal',
        'float',
        'double',
    ];

    /**
     * Tipos que aceptan una lista de valores.
     *
     * @var list<string>
     */
    private const VALUE_TYPES = [
        'enum',
        'set',
    ];

    /**
     * Tipos enteros.
     *
     * @var list<string>
     */
    private const INTEGER_TYPES = [
        'bigint',
        'integer',
        'mediumint',
        'smallint',
        'tinyint',
    ];

    /**
     * Tipos numéricos que aceptan el modificador unsigned.
     *
     * @var list<string>
     */
    private const UNSIGNED_TYPES = [
        'bigint',
        'integer',
        'mediumint',
        'smallint',
        'tinyint',
        'decimal',
        'float',
        'double',
    ];

    /**
     * Determina si el tipo existe en el catálogo.
     */
    public static function exists(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Determina si el tipo acepta longitud.
     */
    public static function supportsLength(string $type): bool
    {
        return in_array($type, self::LENGTH_TYPES, true);
    }

    /**
     * Determina si el tipo acepta precisión y escala.
     */
    public static function supportsPrecision(string $type): bool
    {
        return in_array($type, self::PRECISION_TYPES, true);
    }

    /**
     * Determina si el tipo acepta valores enumerados.
     */
    public static function supportsValues(string $type): bool
    {
        return in_array($type, self::VALUE_TYPES, true);
    }

    /**
     * Determina si el tipo acepta unsigned.
     */
    public static function supportsUnsigned(string $type): bool
    {
        return in_array($type, self::UNSIGNED_TYPES, true);
    }

    /**
     * Determina si el tipo puede ser autoincremental.
     */
    public static function supportsAutoIncrement(string $type): bool
    {
        return in_array($type, self::INTEGER_TYPES, true);
    }

    /**
     * Devuelve el método de migración de Laravel más cercano al tipo.
     */
    public static function laravelMethod(string $type): string
    {
        return match ($type) {
            'bigint' => 'bigInteger',
            'integer' => 'integer',
            'mediumint' => 'mediumInteger',
            'smallint' => 'smallInteger',
            'tinyint' => 'tinyInteger',
            'string' => 'string',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'boolean' => 'boolean',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'date' => 'date',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'time' => 'time',
            'year' => 'year',
            'json' => 'json',
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            'binary' => 'binary',
            'enum' => 'enum',
            'set' => 'set',
            default => $type,
        };
    }

    /**
     * Devuelve el tipo SQL aproximado para documentación.
     */
    public static function sqlType(string $type): string
    {
        return match ($type) {
            'bigint' => 'BIGINT',
            'integer' => 'INT',
            'mediumint' => 'MEDIUMINT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'TINYINT',
            'string' => 'VARCHAR',
            'char' => 'CHAR',
            'text' => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'longtext' => 'LONGTEXT',
            'boolean' => 'BOOLEAN',
            'decimal' => 'DECIMAL',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'YEAR',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
            'ulid' => 'CHAR(26)',
            'binary' => 'BLOB',
            'enum' => 'ENUM',
            'set' => 'SET',
            default => strtoupper($type),
        };
    }
}
