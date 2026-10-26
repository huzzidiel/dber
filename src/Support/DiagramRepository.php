<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Support;

use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Issue;
use Huzzidiel\Dber\Exceptions\DiagramConflictException;
use Huzzidiel\Dber\Exceptions\InvalidDiagramException;
use JsonException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lee y escribe el archivo de esquema.
 *
 * Es la única pieza que conoce el sistema de archivos. La escritura es atómica
 * y opcionalmente optimista: si el archivo cambió desde que el navegador lo
 * cargó, se rechaza el guardado salvo que se fuerce.
 */
final class DiagramRepository
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxSize,
        private readonly SchemaCompiler $compiler,
    ) {
    }

    /**
     * Ruta absoluta del archivo de esquema.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Extensión del archivo: json, yaml o yml.
     */
    public function format(): string
    {
        return strtolower((string) pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * Indica si el archivo existe.
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Hash del contenido actual, o null si el archivo no existe.
     */
    public function hash(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $hash = @hash_file('sha256', $this->path);

        return $hash === false ? null : $hash;
    }

    /**
     * Carga, normaliza y valida el archivo.
     *
     * @throws InvalidDiagramException
     */
    public function load(): CompiledDiagram
    {
        if (! $this->exists()) {
            return $this->compiler->compile([], null, false);
        }

        $size = @filesize($this->path);

        if ($size !== false && $size > $this->maxSize) {
            throw new InvalidDiagramException(
                'The schema file is larger than the configured limit.',
                [
                    new Issue(
                        Issue::ERROR,
                        '$',
                        'The schema file exceeds '.$this->maxSize.' bytes.',
                    ),
                ],
            );
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            throw new InvalidDiagramException(
                'The schema file could not be read.',
                [
                    new Issue(Issue::ERROR, '$', 'Check the file permissions of '.$this->path.'.'),
                ],
            );
        }

        return $this->compiler->compile(
            $this->decode($raw),
            hash('sha256', $raw),
            true,
        );
    }

    /**
     * Valida y guarda el documento de forma atómica.
     *
     * @param array<string, mixed> $input
     * @throws InvalidDiagramException
     * @throws DiagramConflictException
     */
    public function save(array $document, ?string $expectedHash, bool $force = false): CompiledDiagram
    {
        $compiled = $this->compiler->compile($document);

        if ($compiled->hasErrors()) {
            throw new InvalidDiagramException('The schema is invalid.', $compiled->errors());
        }

        $exists = $this->exists();
        $currentHash = $this->hash();

        if ($exists && ! $force) {
            if ($expectedHash === null || $currentHash === null || ! hash_equals($currentHash, $expectedHash)) {
                throw new DiagramConflictException($currentHash ?? '');
            }
        }

        $encoded = $this->encode($compiled->document);

        if (strlen($encoded) > $this->maxSize) {
            throw new InvalidDiagramException(
                'The schema file would exceed the configured limit.',
                [
                    new Issue(
                        Issue::ERROR,
                        '$',
                        'The encoded schema exceeds '.$this->maxSize.' bytes.',
                    ),
                ],
            );
        }

        $this->write($encoded);

        return new CompiledDiagram(
            document: $compiled->document,
            diagram: $compiled->diagram,
            relations: $compiled->relations,
            pivotTables: $compiled->pivotTables,
            issues: $compiled->issues,
            hash: hash('sha256', $encoded),
            exists: true,
        );
    }

    /**
     * Interpreta el contenido según la extensión.
     *
     * @return array<string, mixed>
     * @throws InvalidDiagramException
     */
    private function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (trim($raw) === '') {
            return [];
        }

        $format = $this->format();

        if ($format === 'json') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidDiagramException(
                    'The schema file contains invalid JSON.',
                    [
                        new Issue(
                            Issue::ERROR,
                            '$',
                            'Invalid JSON: '.$exception->getMessage(),
                        ),
                    ],
                );
            }
        } elseif ($format === 'yaml' || $format === 'yml') {
            if (!class_exists(Yaml::class)) {
                throw new InvalidDiagramException(
                    'YAML support is not installed.',
                    [
                        new Issue(
                            Issue::ERROR,
                            '$',
                            'Install symfony/yaml to read .yaml or .yml schema files.',
                        ),
                    ],
                );
            }

            try {
                $decoded = Yaml::parse($raw);
            } catch (\Throwable $exception) {
                throw new InvalidDiagramException(
                    'The schema file contains invalid YAML.',
                    [
                        new Issue(
                            Issue::ERROR,
                            '$',
                            'Invalid YAML: '.$exception->getMessage(),
                        ),
                    ],
                );
            }
        } else {
            throw new InvalidDiagramException(
                'Unsupported schema file extension.',
                [
                    new Issue(
                        Issue::ERROR,
                        '$',
                        'Use a .json, .yaml or .yml file for the schema.',
                    ),
                ],
            );
        }

        if ($decoded === null || $decoded === '') {
            return [];
        }

        if (!is_array($decoded)) {
            throw new InvalidDiagramException(
                'The schema file must contain an object.',
                [
                    new Issue(Issue::ERROR, '$', 'The top-level value must be an object.'),
                ],
            );
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            throw new InvalidDiagramException(
                'The schema file must contain an object.',
                [
                    new Issue(Issue::ERROR, '$', 'The top-level value must be an object, not a list.'),
                ],
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     * @throws InvalidDiagramException
     */
    private function encode(array $document): string
    {
        $format = $this->format();

        if ($format === 'json') {
            try {
                $encoded = json_encode(
                    $document,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new InvalidDiagramException(
                    'The schema could not be encoded as JSON.',
                    [
                        new Issue(Issue::ERROR, '$', $exception->getMessage()),
                    ],
                );
            }

            return $encoded."\n";
        }

        if ($format === 'yaml' || $format === 'yml') {
            if (!class_exists(Yaml::class)) {
                throw new InvalidDiagramException(
                    'YAML support is not installed.',
                    [
                        new Issue(
                            Issue::ERROR,
                            '$',
                            'Install symfony/yaml to write .yaml or .yml schema files.',
                        ),
                    ],
                );
            }

            return Yaml::dump($document, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."\n";
        }

        throw new InvalidDiagramException(
            'Unsupported schema file extension.',
            [
                new Issue(
                    Issue::ERROR,
                    '$',
                    'Use a .json, .yaml or .yml file for the schema.',
                ),
            ],
        );
    }

    /**
     * @throws InvalidDiagramException
     */
    private function write(string $contents): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && ! @mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidDiagramException(
                'The schema directory could not be created.',
                [
                    new Issue(Issue::ERROR, '$', 'Could not create directory '.$directory.'.'),
                ],
            );
        }

        $temporary = tempnam($directory, '.dber-');

        if ($temporary === false) {
            throw new InvalidDiagramException(
                'A temporary file could not be created.',
                [
                    new Issue(Issue::ERROR, '$', 'Check write permissions for '.$directory.'.'),
                ],
            );
        }

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);

            if ($written === false || $written !== strlen($contents)) {
                throw new InvalidDiagramException(
                    'The schema file could not be written.',
                    [
                        new Issue(Issue::ERROR, '$', 'Check write permissions for '.$this->path.'.'),
                    ],
                );
            }

            @chmod($temporary, 0644);

            if (! @rename($temporary, $this->path)) {
                throw new InvalidDiagramException(
                    'The schema file could not be replaced.',
                    [
                        new Issue(Issue::ERROR, '$', 'Check write permissions for '.$this->path.'.'),
                    ],
                );
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
