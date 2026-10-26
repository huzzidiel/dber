<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Exceptions;

use Huzzidiel\Dber\Data\Issue;
use RuntimeException;

/**
 * Excepción lanzada cuando el archivo no puede leerse o interpretarse.
 */
final class InvalidDiagramException extends RuntimeException
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        string $message,
        public readonly array $issues = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, array{severity: string, path: string, message: string}>
     */
    public function issuesToArray(): array
    {
        return array_map(
            static fn (Issue $issue): array => $issue->toArray(),
            $this->issues,
        );
    }
}
