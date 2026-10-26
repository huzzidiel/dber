<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Data;

/**
 * Problema detectado en el archivo de esquema.
 *
 * Los errores impiden guardar; las advertencias se muestran pero no bloquean.
 */
final readonly class Issue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public string $severity,
        public string $path,
        public string $message,
    ) {
    }

    /**
     * Indica si el problema es bloqueante.
     */
    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * @return array{severity: string, path: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
