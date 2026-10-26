<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Diagram;

/**
 * Orquesta normalización, validación y derivación de relaciones.
 */
final class SchemaCompiler
{
    public function __construct(
        private readonly DiagramNormalizer $normalizer,
        private readonly DiagramValidator $validator,
        private readonly RelationResolver $relationResolver,
    ) {
    }

    /**
     * Interpreta un documento y devuelve el diagrama completo.
     */
    public function compile(mixed $input, ?string $hash = null, bool $exists = true): CompiledDiagram
    {
        $document = $this->normalizer->normalize($input);
        $issues = array_merge(
            $this->normalizer->issues(),
            $this->validator->validate($document),
        );

        $diagram = Diagram::fromArray($document);

        return new CompiledDiagram(
            document: $document,
            diagram: $diagram,
            relations: $this->relationResolver->resolve($diagram),
            pivotTables: $this->relationResolver->pivotTables($diagram),
            issues: $issues,
            hash: $hash,
            exists: $exists,
        );
    }
}
