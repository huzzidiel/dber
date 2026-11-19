<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Table;
use Illuminate\Support\Str;

/**
 * Genera o actualiza el modelo Eloquent de una tabla.
 */
final class ModelCodeGenerator
{
    public const LIFECYCLE = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * @return array{namespace: string, class: string}
     */
    public function className(Table $table): array
    {
        if ($table->model !== null && str_contains($table->model, '\\')) {
            $parts = explode('\\', trim($table->model, '\\'));
            $class = (string) array_pop($parts);

            return [
                'namespace' => implode('\\', $parts),
                'class' => $class,
            ];
        }

        return [
            'namespace' => 'App\\Models',
            'class' => Str::studly(Str::singular($table->name)),
        ];
    }

    public function generateNew(Table $table, string $namespace, string $class): string
    {
        $lines = [
            '<?php',
            '',
            'namespace '.$namespace.';',
            '',
            'use Illuminate\\Database\\Eloquent\\Model;',
        ];

        if ($this->usesSoftDeletes($table)) {
            $lines[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        }

        $lines[] = '';
        $lines[] = 'class '.$class.' extends Model';
        $lines[] = '{';

        if ($this->usesSoftDeletes($table)) {
            $lines[] = '    use SoftDeletes;';
            $lines[] = '';
        }

        $defaultTable = Str::snake(Str::plural($class));

        if ($table->name !== $defaultTable) {
            $lines[] = "    protected \$table = '".$this->q($table->name)."';";
            $lines[] = '';
        }

        if ($table->primaryKey !== ['id'] && $table->primaryKey !== []) {
            $lines[] = "    protected \$primaryKey = '".$this->q($table->primaryKey[0])."';";
            $lines[] = '';
        }

        if ($this->hasTimestamps($table) === false) {
            $lines[] = '    public $timestamps = false;';
            $lines[] = '';
        }

        $lines = array_merge($lines, $this->arrayProperty('fillable', $this->fillable($table)));
        $lines = array_merge($lines, $this->castsProperty($this->casts($table)));
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function updateExisting(string $contents, Table $table, string $class): string
    {
        $updated = $this->mergeFillable($contents, $this->fillable($table));
        $updated = $this->mergeCasts($updated, $this->casts($table));

        if ($this->usesSoftDeletes($table) && ! str_contains($updated, 'SoftDeletes')) {
            $updated = $this->addImport($updated, 'Illuminate\\Database\\Eloquent\\SoftDeletes');
            $updated = $this->insertAfterClassOpen($updated, 'use SoftDeletes;');
        }

        if ($this->hasTimestamps($table) === false && ! preg_match('/\$timestamps\s*=/', $updated)) {
            $updated = $this->insertAfterClassOpen($updated, 'public $timestamps = false;');
        }

        $defaultTable = Str::snake(Str::plural($class));

        if ($table->name !== $defaultTable && ! preg_match('/\$table\s*=/', $updated)) {
            $updated = $this->insertAfterClassOpen($updated, "protected \$table = '".$this->q($table->name)."';");
        }

        if ($table->primaryKey !== ['id'] && $table->primaryKey !== [] && ! preg_match('/\$primaryKey\s*=/', $updated)) {
            $updated = $this->insertAfterClassOpen($updated, "protected \$primaryKey = '".$this->q($table->primaryKey[0])."';");
        }

        return $updated;
    }

    /**
     * @return list<string>
     */
    public function fillable(Table $table): array
    {
        return array_values(array_filter(
            array_keys($table->fields),
            static fn (string $name): bool => ! in_array($name, $table->primaryKey, true)
                && ! in_array($name, self::LIFECYCLE, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function casts(Table $table): array
    {
        $casts = [];

        foreach ($table->fields as $field) {
            $cast = $this->castFor($field);

            if ($cast !== null) {
                $casts[$field->name] = $cast;
            }
        }

        return $casts;
    }

    private function castFor(Field $field): ?string
    {
        return match ($field->type) {
            'boolean' => 'boolean',
            'json' => 'array',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'decimal' => 'decimal:'.($field->scale ?? 2),
            'float', 'double' => 'float',
            'integer', 'bigint', 'mediumint', 'smallint', 'tinyint' => 'integer',
            'enum', 'set' => 'string',
            default => null,
        };
    }

    private function usesSoftDeletes(Table $table): bool
    {
        return isset($table->fields['deleted_at']);
    }

    private function hasTimestamps(Table $table): bool
    {
        return isset($table->fields['created_at']) || isset($table->fields['updated_at']);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function arrayProperty(string $name, array $values): array
    {
        $lines = [
            '    protected $'.$name.' = [',
        ];

        foreach ($values as $value) {
            $lines[] = "        '".$this->q($value)."',";
        }

        $lines[] = '    ];';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<string, string> $casts
     * @return list<string>
     */
    private function castsProperty(array $casts): array
    {
        $lines = [
            '    protected $casts = [',
        ];

        foreach ($casts as $field => $cast) {
            $lines[] = "        '".$this->q($field)."' => '".$this->q($cast)."',";
        }

        $lines[] = '    ];';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param list<string> $fields
     */
    private function mergeFillable(string $contents, array $fields): string
    {
        $pattern = '/protected\s+\$fillable\s*=\s*\[(.*?)\];/s';

        if (preg_match($pattern, $contents, $matches) === 1) {
            preg_match_all("/'([^']*)'/", $matches[1], $existing);
            $merged = array_values(array_unique(array_merge($existing[1], $fields)));
            sort($merged);
            $replacement = $this->listProperty('protected $fillable', $merged);

            return preg_replace($pattern, $replacement, $contents, 1) ?? $contents;
        }

        return $this->insertAfterClassOpen($contents, trim($this->propertyBlock('protected $fillable', $fields)));
    }

    /**
     * @param array<string, string> $casts
     */
    private function mergeCasts(string $contents, array $casts): string
    {
        $pattern = '/protected\s+\$casts\s*=\s*\[(.*?)\];/s';

        if (preg_match($pattern, $contents, $matches) === 1) {
            preg_match_all("/'([^']*)'\s*=>\s*'([^']*)'/", $matches[1], $existing, PREG_SET_ORDER);
            $merged = $casts;

            foreach ($existing as $pair) {
                if (! array_key_exists($pair[1], $merged)) {
                    $merged[$pair[1]] = $pair[2];
                }
            }

            $replacement = $this->castsPropertyRaw('protected $casts', $merged);

            return preg_replace($pattern, $replacement, $contents, 1) ?? $contents;
        }

        if ($casts === []) {
            return $contents;
        }

        return $this->insertAfterClassOpen($contents, trim($this->castsPropertyRaw('protected $casts', $casts)));
    }

    /**
     * @param list<string> $values
     */
    private function propertyBlock(string $declaration, array $values): string
    {
        $lines = [
            '',
            $declaration.' = [',
        ];

        foreach ($values as $value) {
            $lines[] = "        '".$this->q($value)."',";
        }

        $lines[] = '    ];';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $values
     */
    private function listProperty(string $declaration, array $values): string
    {
        $block = $declaration.' = [';

        if ($values !== []) {
            $block .= "\n";

            foreach ($values as $value) {
                $block .= "        '".$this->q($value)."',\n";
            }

            $block .= '    ';
        }

        return $block.'];';
    }

    /**
     * @param array<string, string> $values
     */
    private function castsPropertyRaw(string $declaration, array $values): string
    {
        $block = $declaration.' = [';

        if ($values !== []) {
            $block .= "\n";

            foreach ($values as $field => $cast) {
                $block .= "        '".$this->q($field)."' => '".$this->q($cast)."',\n";
            }

            $block .= '    ';
        }

        return $block.'];';
    }

    private function addImport(string $contents, string $class): string
    {
        if (str_contains($contents, $class)) {
            return $contents;
        }

        if (preg_match('/namespace\s+[^;]+;/', $contents, $matches) === 1) {
            return preg_replace(
                '/namespace\s+[^;]+;/',
                $matches[0]."\n\nuse ".$class.';',
                $contents,
                1,
            ) ?? $contents;
        }

        return preg_replace('/<\?php\s*/', "<?php\n\nuse ".$class.';', $contents, 1) ?? $contents;
    }

    private function insertAfterClassOpen(string $contents, string $line): string
    {
        $position = strpos($contents, '{');

        if ($position === false) {
            return $contents;
        }

        return substr($contents, 0, $position + 1)."\n    ".$line."\n".substr($contents, $position + 1);
    }

    private function q(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
