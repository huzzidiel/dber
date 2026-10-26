<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\Diagram;
use Huzzidiel\Dber\Data\Field;
use Huzzidiel\Dber\Data\Relation;
use Huzzidiel\Dber\Data\Table;

/**
 * Deriva las relaciones del diagrama a partir de las claves foráneas.
 *
 * El archivo declara cada clave foránea una sola vez, en la columna que la
 * contiene. Las relaciones inversas y las relaciones muchos-a-muchos se
 * calculan aquí para no duplicar información editable.
 */
final class RelationResolver
{
    /**
     * @return list<Relation>
     */
    public function resolve(Diagram $diagram): array
    {
        $relations = [];

        foreach ($diagram->tables as $table) {
            foreach ($table->fields as $field) {
                $reference = $field->references;

                if ($reference === null) {
                    continue;
                }

                $target = $diagram->table($reference->table);

                if ($target === null || $target->field($reference->field) === null) {
                    continue;
                }

                $relations[] = new Relation(
                    id: 'fk:'.$table->name.'.'.$field->name,
                    kind: $this->isUnique($table, $field)
                        ? Relation::ONE_TO_ONE
                        : Relation::MANY_TO_ONE,
                    fromTable: $table->name,
                    fromField: $field->name,
                    toTable: $target->name,
                    toField: $reference->field,
                    nullable: $field->nullable,
                    onDelete: $reference->onDelete,
                    onUpdate: $reference->onUpdate,
                );
            }
        }

        foreach ($this->pivotTables($diagram) as $pivotName) {
            $pivot = $diagram->table($pivotName);

            if ($pivot === null) {
                continue;
            }

            $foreignKeys = array_values($pivot->foreignKeys());

            if (count($foreignKeys) !== 2) {
                continue;
            }

            $first = $foreignKeys[0];
            $second = $foreignKeys[1];

            if ($first->references === null || $second->references === null) {
                continue;
            }

            $firstTarget = $diagram->table($first->references->table);
            $secondTarget = $diagram->table($second->references->table);

            if ($firstTarget === null || $secondTarget === null) {
                continue;
            }

            $relations[] = new Relation(
                id: 'many-to-many:'.$pivot->name,
                kind: Relation::MANY_TO_MANY,
                fromTable: $firstTarget->name,
                fromField: $first->name,
                toTable: $secondTarget->name,
                toField: $second->name,
                pivotTable: $pivot->name,
            );
        }

        return $relations;
    }

    /**
     * Nombres de las tablas pivote del diagrama.
     *
     * @return list<string>
     */
    public function pivotTables(Diagram $diagram): array
    {
        $pivots = [];

        foreach ($diagram->tables as $table) {
            if ($this->isPivot($table)) {
                $pivots[] = $table->name;
            }
        }

        return $pivots;
    }

    /**
     * Determina si una tabla representa una relación muchos-a-muchos.
     *
     * La marca es explícita para que el archivo no dependa de heurísticas: una
     * tabla pivote debe declarar `"pivot": true`.
     */
    public function isPivot(Table $table): bool
    {
        return $table->pivot;
    }

    /**
     * Indica si la columna es única por sí sola.
     */
    public function isUnique(Table $table, Field $field): bool
    {
        if ($field->unique) {
            return true;
        }

        if ($table->primaryKey === [$field->name]) {
            return true;
        }

        foreach ($table->indexes as $index) {
            if ($index->unique && $index->columns === [$field->name]) {
                return true;
            }
        }

        return false;
    }
}
