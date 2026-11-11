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
     * @return list<array{op: string, table: string, new_table: string|null, body: string|null}>
     */
    public function parse(string $contents): array
    {
        $operations = [];

        foreach ($this->schemaCalls($contents) as $call) {
            $operations[] = $this->parseCall($call['op'], $call['statement']);
        }

        return $operations;
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
                'table' => $this->stringValue($args[0] ?? ''),
                'new_table' => null,
                'body' => $this->closureBody($statement),
            ];
        }

        if ($op === 'drop' || $op === 'dropIfExists') {
            return [
                'op' => 'drop',
                'table' => $this->stringValue($args[0] ?? ''),
                'new_table' => null,
                'body' => null,
            ];
        }

        return [
            'op' => 'rename',
            'table' => $this->stringValue($args[0] ?? ''),
            'new_table' => $this->stringValue($args[1] ?? ''),
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

        $depth = 0;

        for ($index = $open; $index < strlen($statement); $index += 1) {
            if ($statement[$index] === '{') {
                $depth += 1;
                continue;
            }

            if ($statement[$index] === '}') {
                $depth -= 1;

                if ($depth === 0) {
                    return substr($statement, $open + 1, $index - $open - 1);
                }
            }
        }

        return null;
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
