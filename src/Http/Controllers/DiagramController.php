<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Http\Controllers;

use Carbon\CarbonImmutable;
use Huzzidiel\Dber\Data\CompiledDiagram;
use Huzzidiel\Dber\Data\Issue;
use Huzzidiel\Dber\Exceptions\DiagramConflictException;
use Huzzidiel\Dber\Exceptions\InvalidDiagramException;
use Huzzidiel\Dber\Support\DiagramRepository;
use Huzzidiel\Dber\Support\MarkdownExporter;
use Huzzidiel\Dber\Support\MermaidExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Expone el visor, el editor, los datos y las exportaciones del diagrama.
 */
final class DiagramController
{
    public function __construct(
        private readonly DiagramRepository $repository,
        private readonly MarkdownExporter $markdownExporter,
        private readonly MermaidExporter $mermaidExporter,
    ) {
    }

    public function index(): View
    {
        return view('dber::diagram', [
            'dataUrl' => route('dber.data'),
            'saveUrl' => route('dber.data.update'),
            'contextUrl' => route('dber.context'),
            'mermaidUrl' => route('dber.mermaid'),
            'jsUrl' => route('dber.asset', ['path' => 'js/dber.js']),
            'cssUrl' => route('dber.asset', ['path' => 'css/dber.css']),
            'faviconUrl' => route('dber.asset', ['path' => 'favicon.svg']),
            'editable' => (bool) config('dber.editor'),
            'pollInterval' => (int) config('dber.poll_interval'),
            'schemaPath' => $this->displayPath(),
            'format' => $this->repository->format(),
        ]);
    }

    public function data(): JsonResponse
    {
        try {
            $compiled = $this->repository->load();
        } catch (InvalidDiagramException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->issuesToArray(),
                422,
                [
                    'path' => $this->displayPath(),
                ],
            );
        }

        return $this->success('Schema loaded.', $this->payload($compiled));
    }

    public function update(Request $request): JsonResponse
    {
        if (! (bool) config('dber.editor')) {
            return $this->error('The diagram editor is disabled.', [], 403);
        }

        $document = $request->input('document');

        if (!is_array($document)) {
            return $this->error(
                'The request must contain a document object.',
                [
                    [
                        'severity' => 'error',
                        'path' => 'document',
                        'message' => 'The document property must be an object.',
                    ],
                ],
            );
        }

        $hash = $request->input('hash');
        $hash = is_string($hash) ? $hash : null;

        try {
            $compiled = $this->repository->save(
                document: $document,
                expectedHash: $hash,
                force: $request->boolean('force'),
            );
        } catch (InvalidDiagramException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->issuesToArray(),
                422,
            );
        } catch (DiagramConflictException $exception) {
            return $this->error(
                'The schema file changed on disk. Reload it or force the save.',
                [],
                409,
                [
                    'hash' => $exception->currentHash,
                    'path' => $this->displayPath(),
                ],
            );
        }

        return $this->success('Schema saved.', $this->payload($compiled));
    }

    public function context(): Response|JsonResponse
    {
        try {
            $compiled = $this->repository->load();
        } catch (InvalidDiagramException $exception) {
            return $this->error($exception->getMessage(), $exception->issuesToArray(), 422);
        }

        if (! $compiled->exists) {
            return $this->error('The schema file does not exist yet.', [], 404);
        }

        if ($compiled->hasErrors()) {
            return $this->error(
                'Fix the schema errors before exporting the AI context.',
                array_map(
                    static fn (Issue $issue): array => $issue->toArray(),
                    $compiled->errors(),
                ),
                422,
            );
        }

        $markdown = $this->markdownExporter->export($compiled, $this->displayPath());
        $filename = $this->filename($compiled, 'context', 'md');

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function mermaid(): Response|JsonResponse
    {
        try {
            $compiled = $this->repository->load();
        } catch (InvalidDiagramException $exception) {
            return $this->error($exception->getMessage(), $exception->issuesToArray(), 422);
        }

        if (! $compiled->exists) {
            return $this->error('The schema file does not exist yet.', [], 404);
        }

        if ($compiled->hasErrors()) {
            return $this->error(
                'Fix the schema errors before exporting the Mermaid diagram.',
                array_map(
                    static fn (Issue $issue): array => $issue->toArray(),
                    $compiled->errors(),
                ),
                422,
            );
        }

        $mermaid = $this->mermaidExporter->export($compiled);
        $filename = $this->filename($compiled, 'diagram', 'mmd');

        return response($mermaid, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function schema(): BinaryFileResponse
    {
        $published = dirname($this->repository->path()).'/schema.json';
        $path = is_file($published)
            ? $published
            : __DIR__.'/../../../resources/stubs/schema.json';

        return response()->file($path, [
            'Content-Type' => 'application/schema+json; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CompiledDiagram $compiled): array
    {
        $payload = $compiled->toArray();
        $payload['path'] = $this->displayPath();
        $payload['format'] = $this->repository->format();
        $payload['editable'] = (bool) config('dber.editor');
        $payload['poll_interval'] = (int) config('dber.poll_interval');
        $payload['generated_at'] = CarbonImmutable::now()->toIso8601String();

        return $payload;
    }

    /**
     * @param array<int, array<string, string>> $errors
     * @param array<string, mixed> $data
     */
    private function error(
        string $message,
        array $errors = [],
        int $status = 422,
        array $data = [],
    ): JsonResponse
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
        ];

        if ($data !== []) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function success(string $message, array $data): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function displayPath(): string
    {
        $path = $this->repository->path();
        $base = base_path();

        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private function filename(CompiledDiagram $compiled, string $suffix, string $extension): string
    {
        $name = Str::slug($compiled->diagram->name);

        if ($name === '') {
            $name = 'schema';
        }

        return $name.'-'.$suffix.'.'.$extension;
    }
}
