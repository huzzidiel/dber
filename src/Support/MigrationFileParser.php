<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

/**
 * Extrae de un archivo de migración las llamadas a Schema y, dentro de cada
 * Blueprint, las instrucciones sobre columnas e índices.
 *
 * Es un parser estático: no ejecuta la migración. Soporta el DSL estándar de
 * Laravel; las construcciones dinámicas se marcan como advertencias.
 */
final class MigrationFileParser
{
    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Nombres por defecto de las tablas de Spatie Permission.
     *
     * @var array<string, string>
     */
    private const TABLE_NAME_DEFAULTS = [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
        'role_has_permissions' => 'role_has_permissions',
    ];

    /**
     * Nombres por defecto de las columnas de Spatie Permission.
     *
     * @var array<string, string>
     */
    private const COLUMN_NAME_DEFAULTS = [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ];

    /**
     * Resuelve nombres dinámicos comunes de Spatie a sus valores por defecto.
     */
    public static function resolveName(string $value): string
    {
        $value = trim($value);

        if ($value === '$pivotPermission') {
            return 'permission_id';
        }

        if ($value === '$pivotRole') {
            return 'role_id';
        }

        if (preg_match('/^\$tableNames\s*\[\s*[\'"]([a-z_]+)[\'"]\s*\]$/', $value, $matches) === 1) {
            return self::TABLE_NAME_DEFAULTS[$matches[1]] ?? $value;
        }

        if (preg_match('/^\$columnNames\s*\[\s*[\'"]([a-z_]+)[\'"]\s*\]$/', $value, $matches) === 1) {
            return self::COLUMN_NAME_DEFAULTS[$matches[1]] ?? $value;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<array{op: string, table: string, new_table: string|null, body: string|null}>
     */
    public function parse(string $contents): array
    {
        $this->warnings = [];
        $operations = [];
        $source = $this->upBody($contents);

        foreach ($this->schemaCalls($source) as $call) {
            $operation = $this->parseCall($call['op'], $call['statement']);

            if ($operation['table'] !== '' && ! $this->isIdentifier($operation['table'])) {
                $this->warnings[] = 'Skipped dynamic table name: '.$operation['table'];
                continue;
            }

            if ($operation['new_table'] !== null && $operation['new_table'] !== ''
                && ! $this->isIdentifier($operation['new_table'])) {
                $this->warnings[] = 'Skipped dynamic table rename target: '.$operation['new_table'];
                continue;
            }

            $operations[] = $operation;
        }

        return $operations;
    }

    /**
     * Elimina los bloques `if ($teams ...)` de las migraciones de Spatie, cuyo
     * contenido solo aplica cuando la configuración de equipos está activa.
     */
    private function stripTeamsBranches(string $body): string
    {
        while (preg_match('/\bif\s*\([^)]*\$teams[^)]*\)/', $body, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $start = $matches[0][1];
            $open = strpos($body, '{', $start);

            if ($open === false) {
                break;
            }

            $close = $this->braceEnd($body, $open);

            if ($close === null) {
                break;
            }

            $body = substr($body, 0, $start).substr($body, $close + 1);
        }

        return $body;
    }

    /**
     * Divide el cuerpo de un Blueprint en instrucciones con sus métodos.
     *
     * @return list<list<array{method: string, args: list<mixed>}>>
     */
    public function parseBlueprintBody(string $body): array
    {
        $statements = [];
        $result = [];
        $body = $this->stripTeamsBranches($body);

        foreach ($this->splitStatements($body) as $statement) {
            $calls = $this->methodCalls($statement);

            if ($calls !== []) {
                $result[] = $calls;
            }
        }

        return $result;
    }

    /**
     * @return list<array{op: string, statement: string}>
     */
    private function schemaCalls(string $source): array
    {
        $calls = [];
        $pattern = '/Schema::(?:connection\s*\([^()]*\)\s*->\s*)?(create|table|dropIfExists|drop|rename)\b/';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $calls;
        }

        foreach ($matches[0] as $index => $match) {
            $op = $matches[1][$index][0];
            $end = $this->statementEnd($source, $match[1]);

            if ($end === null) {
                continue;
            }

            $calls[] = [
                'op' => $op,
                'statement' => substr($source, $match[1], $end - $match[1] + 1),
            ];
        }

        return $calls;
    }

    /**
     * @return array{op: string, table: string, new_table: string|null, body: string|null}
     */
    private function parseCall(string $op, string $statement): array
    {
        $args = $this->callArguments($statement);

        if ($op === 'create' || $op === 'table') {
            return [
                'op' => $op,
                'table' => self::resolveName($this->stringValue($args[0] ?? '')),
                'new_table' => null,
                'body' => $this->closureBody($statement),
            ];
        }

        if ($op === 'drop' || $op === 'dropIfExists') {
            return [
                'op' => 'drop',
                'table' => self::resolveName($this->stringValue($args[0] ?? '')),
                'new_table' => null,
                'body' => null,
            ];
        }

        return [
            'op' => 'rename',
            'table' => self::resolveName($this->stringValue($args[0] ?? '')),
            'new_table' => self::resolveName($this->stringValue($args[1] ?? '')),
            'body' => null,
        ];
    }

    /**
     * Argumentos de la llamada Schema::create/table/drop/rename.
     *
     * @return list<string>
     */
    private function callArguments(string $statement): array
    {
        $open = $this->findOpenParen($statement);

        if ($open === null) {
            return [];
        }

        $close = $this->balancedEnd($statement, $open);

        if ($close === null) {
            return [];
        }

        return $this->splitTopLevel(substr($statement, $open + 1, $close - $open - 1), ',');
    }

    private function closureBody(string $statement): ?string
    {
        $position = strpos($statement, 'function');

        if ($position === false) {
            return null;
        }

        $open = strpos($statement, '{', $position);

        if ($open === false) {
            return null;
        }

        $close = $this->braceEnd($statement, $open);

        if ($close === null) {
            return null;
        }

        return substr($statement, $open + 1, $close - $open - 1);
    }

    /**
     * Devuelve solo el cuerpo del método up(), para ignorar down().
     */
    private function upBody(string $source): string
    {
        if (preg_match('/\bfunction\s+up\s*\([^)]*\)\s*(?::\s*\w+)?\s*\{/', $source, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $open = strpos($source, '{', $matches[0][1]);

            if ($open !== false) {
                $close = $this->braceEnd($source, $open);

                if ($close !== null) {
                    return substr($source, $open + 1, $close - $open - 1);
                }
            }
        }

        return $source;
    }

    private function braceEnd(string $text, int $open): ?int
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $inLine = false;
        $inBlock = false;
        $length = strlen($text);

        for ($index = $open; $index < $length; $index += 1) {
            $char = $text[$index];
            $next = $index + 1 < $length ? $text[$index + 1] : '';

            if ($inBlock) {
                if ($char === '*' && $next === '/') {
                    $inBlock = false;
                    $index += 1;
                }
                continue;
            }

            if ($inLine) {
                if ($char === "\n") {
                    $inLine = false;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlock = true;
                $index += 1;
                continue;
            }

            if ($char === '/' && $next === '/') {
                $inLine = true;
                $index += 1;
                continue;
            }

            if ($inSingle) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                continue;
            }

            if ($char === '{') {
                $depth += 1;
                continue;
            }

            if ($char === '}') {
                $depth -= 1;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $body): array
    {
        $statements = [];
        $start = 0;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index += 1) {
            if ($body[$index] === ';' && $this->isTopLevel($body, $index)) {
                $statement = trim(substr($body, $start, $index - $start));

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $start = $index + 1;
            }
        }

        $tail = trim(substr($body, $start));

        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * @return list<array{method: string, args: list<mixed>}>
     */
    private function methodCalls(string $statement): array
    {
        $calls = [];

        if (preg_match_all('/\-\>\s*([A-Za-z_]\w*)\s*\(/', $statement, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $calls;
        }

        foreach ($matches[1] as $index => $methodMatch) {
            $method = $methodMatch[0];
            $open = strpos($statement, '(', $methodMatch[1]);

            if ($open === false) {
                continue;
            }

            $close = $this->balancedEnd($statement, $open);

            if ($close === null) {
                continue;
            }

            $calls[] = [
                'method' => $method,
                'args' => $this->splitArgs(substr($statement, $open + 1, $close - $open - 1)),
            ];
        }

        return $calls;
    }

    /**
     * @return list<mixed>
     */
    private function splitArgs(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_map(
            fn (string $part): mixed => $this->parseArg($part),
            $this->splitTopLevel($raw, ','),
        );
    }

    private function parseArg(string $raw): mixed
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if ($value === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"')) {
            return $this->unquote($value);
        }

        if ($value[0] === '[') {
            $inner = substr($value, 1, -1);

            return array_map(
                fn (string $part): mixed => $this->parseArg($part),
                $this->splitTopLevel($inner, ','),
            );
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $raw, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $length = strlen($raw);

        for ($index = 0; $index < $length; $index += 1) {
            if ($raw[$index] === $delimiter && $this->isTopLevel($raw, $index)) {
                $parts[] = trim(substr($raw, $start, $index - $start));
                $start = $index + 1;
            }
        }

        $parts[] = trim(substr($raw, $start));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function isTopLevel(string $text, int $offset): bool
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $inLine = false;
        $inBlock = false;

        for ($index = 0; $index < $offset; $index += 1) {
            $char = $text[$index];
            $next = $index + 1 < $offset ? $text[$index + 1] : '';

            if ($inBlock) {
                if ($char === '*' && $next === '/') {
                    $inBlock = false;
                    $index += 1;
                }
                continue;
            }

            if ($inLine) {
                if ($char === "\n") {
                    $inLine = false;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlock = true;
                $index += 1;
                continue;
            }

            if ($char === '/' && $next === '/') {
                $inLine = true;
                $index += 1;
                continue;
            }

            if ($inSingle) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth += 1;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth -= 1;
            }
        }

        return $depth === 0 && ! $inSingle && ! $inDouble;
    }

    private function statementEnd(string $source, int $offset): ?int
    {
        $length = strlen($source);

        for ($index = $offset; $index < $length; $index += 1) {
            if ($source[$index] === ';' && $this->isTopLevel($source, $index)) {
                return $index;
            }
        }

        return null;
    }

    private function balancedEnd(string $text, int $open): ?int
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $length = strlen($text);

        for ($index = $open; $index < $length; $index += 1) {
            $char = $text[$index];

            if ($inSingle) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($char === '\\') {
                    $index += 1;
                } elseif ($char === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                continue;
            }

            if ($char === '(') {
                $depth += 1;
                continue;
            }

            if ($char === ')') {
                $depth -= 1;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function findOpenParen(string $statement): ?int
    {
        $position = strpos($statement, '(');

        return $position === false ? null : $position;
    }

    private function stringValue(string $raw): string
    {
        $value = trim($raw);

        if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"')) {
            return (string) $this->unquote($value);
        }

        return $value;
    }

    private function unquote(string $value): string
    {
        $quote = $value[0];
        $inner = substr($value, 1, -1);

        return str_replace('\\'.$quote, $quote, $inner);
    }
}
