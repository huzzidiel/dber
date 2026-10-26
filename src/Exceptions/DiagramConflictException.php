<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Exceptions;

use RuntimeException;

/**
 * Excepción lanzada cuando el archivo cambió en disco desde que se cargó.
 */
final class DiagramConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $currentHash,
    ) {
        parent::__construct('The schema file changed on disk since it was loaded.');
    }
}
