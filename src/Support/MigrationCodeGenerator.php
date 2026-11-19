<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Table;

/**
 * Genera el contenido PHP de una migración a partir de una tabla.
 *
 * Soporta dos casos: crear una tabla nueva o añadir campos a una tabla que ya
 * tiene migración.
 */
final class MigrationCodeGenerator
{
    /**
     * @var array<string, string>
     */
    private const TYPE_METHODS = [
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
    ];

    /**
     * @var array<string, string>
     */
    private const INCREMENT_METHODS = [
        'bigint' => 'bigIncrements',
        'integer' => 'increments',
        'mediumint' => 'mediumIncrements',
        'smallint' => 'smallIncrements',
        'tinyint' => 'tinyIncrements',
    ];

    public function createMigration(Table $table): string
    {
        $lines = $this->columnLines($table);
        $lines = array_merge($lines, $this->indexLines($table));
        $lines = array_merge($lines, $this->foreignLines($table));

        if (count($table->primaryKey) > 1) {
            $lines[] = "\$table->primary(['".implode("', '", array_map(fn (string $column): string => $this->q($column), $table->primaryKey))."']);";
        }

        $lines = array_merge($lines, $this->timestampsLines($table));
        $body = $this->indent($lines, 12);

        return $this->migration(
            $this->q($table->name),
            'create',
            "        Schema::create('".$this->q($table->name)."', function (Blueprint \$table) {\n".$body."\n        });",
            "        Schema::dropIfExists('".$this->q($table->name)."');",
        );
    }

    /**
     * @param list<Field> $fields
     */
    public function alterMigration(Table $table, array $fields): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $lines[] = $this->columnLine($table, $field, true);
        }

        foreach ($fields as $field) {
            if ($field->references !== null) {
                $lines[] = $this->foreignLine($field);
            }
        }

        $lines = array_merge($lines, $this->timestampsLines($table, $fields));

        $body = $this->indent($lines, 12);

        $names = array_map(static fn (Field $field): string => $field->name, $fields);
        $dropped = array_values(array_filter(
            $names,
            static fn (string $name): bool => ! in_array($name, ['created_at', 'updated_at', 'deleted_at'], true),
        ));

        $downLines = [];

        if ($dropped !== []) {
            $downLines[] = "\$table->dropColumn(['".implode("', '", array_map(fn (string $name): string => $this->q($name), $dropped))."']);";
        }

        if (in_array('created_at', $names, true) || in_array('updated_at', $names, true)) {
            $downLines[] = "\$table->dropColumn(['created_at', 'updated_at']);";
        }

        if (in_array('deleted_at', $names, true)) {
            $downLines[] = '$table->dropSoftDeletes();';
        }

        $downBody = $downLines === []
            ? str_repeat(' ', 12).'// No reverse action needed.'
            : $this->indent($downLines, 12);

        $down = "        Schema::table('".$this->q($table->name)."', function (Blueprint \$table) {\n"
            .$downBody."\n"
            ."        });";

        return $this->migration(
            $this->q($table->name),
            'table',
            "        Schema::table('".$this->q($table->name)."', function (Blueprint \$table) {\n".$body."\n        });",
            $down,
        );
    }

    /**
     * @return list<string>
     */
    private function columnLines(Table $table): array
    {
        $lines = [];

        foreach ($table->fields as $field) {
            if (in_array($field->name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $lines[] = $this->columnLine($table, $field, false);
        }

        return $lines;
    }

    private function columnLine(Table $table, Field $field, bool $alter): string
    {
        $name = $field->name;
        $isSinglePrimary = $table->primaryKey === [$name];

        if (! $alter && $isSinglePrimary && $field->autoIncrement) {
            if ($name === 'id' && $field->type === 'bigint') {
                return '$table->id();';
            }

            if (isset(self::INCREMENT_METHODS[$field->type])) {
                return "\$table->".self::INCREMENT_METHODS[$field->type]."('".$this->q($name)."');";
            }
        }

        $method = self::TYPE_METHODS[$field->type] ?? $field->type;
        $args = ["'".$this->q($name)."'"];

        if ($field->length !== null && in_array($field->type, ['string', 'char', 'binary'], true)) {
            $args[] = (string) $field->length;
        }

        if (in_array($field->type, ['decimal', 'float', 'double'], true)) {
            if ($field->precision !== null) {
                $args[] = (string) $field->precision;
            }

            if ($field->scale !== null) {
                $args[] = (string) $field->scale;
            }
        }

        if ($field->values !== [] && in_array($field->type, ['enum', 'set'], true)) {
            $args[] = "['".implode("', '", array_map(fn (string $value): string => $this->q($value), $field->values))."']";
        }

        $line = "\$table->".$method.'('.implode(', ', $args).')';

        if ($field->unsigned && ! $field->autoIncrement) {
            $line .= '->unsigned()';
        }

        if ($field->autoIncrement && ! $isSinglePrimary) {
            $line .= '->autoIncrement()';
        }

        if ($field->nullable) {
            $line .= '->nullable()';
        }

        if ($field->hasDefault) {
            $line .= '->default('.$this->literal($field->default).')';
        }

        if ($field->unique) {
            $line .= '->unique()';
        }

        if ($isSinglePrimary && ! $field->autoIncrement) {
            $line .= '->primary()';
        }

        return $line.';';
    }

    /**
     * @return list<string>
     */
    private function indexLines(Table $table): array
    {
        $lines = [];

        foreach ($table->indexes as $index) {
            if ($index->unique && count($index->columns) === 1
                && ($table->field($index->columns[0])?->unique ?? false)) {
                continue;
            }

            $method = $index->unique ? 'unique' : 'index';
            $columns = "['".implode("', '", array_map(fn (string $column): string => $this->q($column), $index->columns))."']";
            $lines[] = "\$table->".$method.'('.$columns.');';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function foreignLines(Table $table): array
    {
        $lines = [];

        foreach ($table->fields as $field) {
            if ($field->references !== null) {
                $lines[] = $this->foreignLine($field);
            }
        }

        return $lines;
    }

    private function foreignLine(Field $field): string
    {
        $reference = $field->references;
        $line = "\$table->foreign('".$this->q($field->name)."')->references('".$this->q($reference->field)."')->on('".$this->q($reference->table)."')";

        if ($reference->onDelete !== null) {
            $line .= "->onDelete('".$this->referentialAction($reference->onDelete)."')";
        }

        if ($reference->onUpdate !== null) {
            $line .= "->onUpdate('".$this->referentialAction($reference->onUpdate)."')";
        }

        return $line.';';
    }

    /**
     * @param list<Field>|null $only
     * @return list<string>
     */
    private function timestampsLines(Table $table, ?array $only = null): array
    {
        $lines = [];
        $names = $only === null ? array_keys($table->fields) : array_map(static fn (Field $field): string => $field->name, $only);

        $hasCreated = in_array('created_at', $names, true);
        $hasUpdated = in_array('updated_at', $names, true);
        $hasDeleted = in_array('deleted_at', $names, true);

        if ($hasCreated && $hasUpdated) {
            $lines[] = '$table->timestamps();';
        } else {
            if ($hasCreated) {
                $lines[] = "\$table->timestamp('created_at')->nullable();";
            }

            if ($hasUpdated) {
                $lines[] = "\$table->timestamp('updated_at')->nullable();";
            }
        }

        if ($hasDeleted) {
            $lines[] = '$table->softDeletes();';
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function indent(array $lines, int $spaces): string
    {
        $padding = str_repeat(' ', $spaces);

        return implode("\n", array_map(static fn (string $line): string => $padding.$line, $lines));
    }

    private function migration(string $table, string $operation, string $up, string $down): string
    {
        return "<?php\n\n"
            ."use Illuminate\\Database\\Migrations\\Migration;\n"
            ."use Illuminate\\Database\\Schema\\Blueprint;\n"
            ."use Illuminate\\Support\\Facades\\Schema;\n\n"
            ."return new class extends Migration\n"
            ."{\n"
            ."    public function up(): void\n"
            ."    {\n"
            .$up."\n"
            ."    }\n\n"
            ."    public function down(): void\n"
            ."    {\n"
            .$down."\n"
            ."    }\n"
            ."};\n";
    }

    private function referentialAction(string $action): string
    {
        return match ($action) {
            'set_null' => 'set null',
            'no_action' => 'no action',
            'set_default' => 'set default',
            default => $action,
        };
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace("'", "\\'", (string) $value)."'";
    }

    private function q(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
