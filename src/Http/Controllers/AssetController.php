<?php

declare(strict_types=1);

namespace Huzzidiel\Dber\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve los assets del paquete sin publicarlos.
 *
 * Solo se permiten archivos dentro de resources/js, resources/css y el
 * favicon. La ruta se valida con realpath para impedir traversal.
 */
final class AssetController
{
    /**
     * Extensiones permitidas y su content type.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'js' => 'text/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'svg' => 'image/svg+xml',
    ];

    public function show(string $path): BinaryFileResponse
    {
        $path = str_replace('\\', '/', $path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (! isset(self::MIME_TYPES[$extension])) {
            abort(404);
        }

        $file = $this->resolve($path);

        if ($file === null) {
            abort(404);
        }

        return response()->file($file, [
            'Content-Type' => self::MIME_TYPES[$extension],
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resolve(string $path): ?string
    {
        if ($path === 'favicon.svg') {
            $candidate = __DIR__.'/../../../resources/stubs/favicon.svg';
        } elseif (str_starts_with($path, 'js/') || str_starts_with($path, 'css/')) {
            $candidate = __DIR__.'/../../../resources/'.$path;
        } else {
            return null;
        }

        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return null;
        }

        $resources = realpath(__DIR__.'/../../../resources');

        if ($resources === false || !str_starts_with($real, $resources.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
