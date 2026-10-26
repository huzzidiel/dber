<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Relation;

/**
 * Exporta el diagrama en sintaxis Mermaid erDiagram.
 */
final class MermaidExporter
{
    public function export(CompiledDiagram $compiled): string
    {
        $diagram = $compiled->diagram;
        $lines = [
            'erDiagram',
        ];

        foreach ($diagram->tables as $table) {
            $identifier = $this->identifier($table->name);
            $lines[] = '    '.$identifier.' {';

            foreach ($table->fields as $field) {
                $lines[] = '        '.$this->fieldLine($table->isPrimaryKey($field->name), $field);
            }

            $lines[] = '    }';
        }

        foreach ($compiled->relations as $relation) {
            if ($relation->kind === Relation::MANY_TO_MANY) {
                $lines[] = '    %% Many-to-many via '.$relation->pivotTable;
            }
        }

        foreach ($compiled->relations as $relation) {
            if ($relation->kind === Relation::MANY_TO_MANY) {
                continue;
            }

            $lines[] = $this->relationshipLine($relation);
        }

        return implode("\n", $lines)."\n";
    }

    private function fieldLine(bool $primary, Field $field): string
    {
        $keys = [];

        if ($primary) {
            $keys[] = 'PK';
        }

        if ($field->references !== null) {
            $keys[] = 'FK';
        }

        if ($field->unique) {
            $keys[] = 'UK';
        }

        $line = TypeRegistry::sqlType($field->type).' '.$this->identifier($field->name);

        if ($keys !== []) {
            $line .= ' '.implode(', ', $keys);
        }

        return $line;
    }

    private function relationshipLine(Relation $relation): string
    {
        $from = $this->identifier($relation->fromTable);
        $to = $this->identifier($relation->toTable);
        $label = $this->label((string) $relation->fromField);

        if ($relation->kind === Relation::ONE_TO_ONE) {
            $right = $relation->nullable ? 'o|' : '||';

            return '    '.$to.' ||--'.$right.' '.$from.' : "'.$label.'"';
        }

        return '    '.$to.' ||--o{ '.$from.' : "'.$label.'"';
    }

    private function identifier(string $value): string
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $value) ?? $value;

        if ($identifier === '' || preg_match('/^[0-9]/', $identifier) === 1) {
            $identifier = 'T_'.$identifier;
        }

        return $identifier;
    }

    private function label(string $value): string
    {
        return str_replace('"', "'", $value);
    }
}
