<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Resultado de interpretar un archivo de esquema.
 *
 * Incluye el documento normalizado, el diagrama tipado, las relaciones
 * derivadas, los problemas detectados y metadatos del archivo.
 */
final readonly class CompiledDiagram
{
    /**
     * @param array<string, mixed> $document
     * @param list<Relation> $relations
     * @param list<string> $pivotTables
     * @param list<Issue> $issues
     */
    public function __construct(
        public array $document,
        public Diagram $diagram,
        public array $relations,
        public array $pivotTables,
        public array $issues,
        public ?string $hash = null,
        public bool $exists = false,
    ) {
    }

    /**
     * Indica si hay al menos un error bloqueante.
     */
    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => $issue->isError(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document' => $this->document,
            'relations' => array_map(
                static fn (Relation $relation): array => $relation->toArray(),
                $this->relations,
            ),
            'pivot_tables' => $this->pivotTables,
            'issues' => array_map(
                static fn (Issue $issue): array => $issue->toArray(),
                $this->issues,
            ),
            'hash' => $this->hash,
            'exists' => $this->exists,
            'stats' => [
                'tables' => count($this->diagram->tables),
                'fields' => $this->diagram->fieldCount(),
                'relations' => count($this->relations),
                'pivots' => count($this->pivotTables),
            ],
        ];
    }
}
