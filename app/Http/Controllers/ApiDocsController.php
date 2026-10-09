<?php

namespace App\Http\Controllers;

use App\Support\OpenApiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the OpenAPI document and the Swagger UI viewer.
 */
class ApiDocsController extends Controller
{
    private const ASSET_ROOT = 'vendor/swagger-api/swagger-ui/dist';

    public function __construct(private readonly OpenApiGenerator $openApi) {}

    /**
     * The raw specification, for codegen and import into other tools.
     */
    public function spec(): JsonResponse
    {
        return response()->json($this->openApi->generate());
    }

    /**
     * Swagger UI. Public, because the documentation itself is not sensitive
     * and a locked viewer helps nobody; every endpoint it documents still
     * requires a token.
     */
    public function ui(): Response
    {
        $index = base_path(self::ASSET_ROOT.'/index.html');

        if (! File::exists($index)) {
            return response()->make(
                'Swagger UI assets are not installed. Run: composer require swagger-api/swagger-ui',
                500
            );
        }

        // The vendor bundle references its assets relatively and points at
        // the petstore spec, so both are rewritten onto this application.
        $html = str_replace('./', url('/api/docs/assets').'/', File::get($index));

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * The Swagger UI initializer, with the spec URL pointing here.
     */
    public function initializer(): Response
    {
        $file = base_path(self::ASSET_ROOT.'/swagger-initializer.js');

        if (! File::exists($file)) {
            abort(404);
        }

        $js = str_replace(
            'https://petstore.swagger.io/v2/swagger.json',
            url('/api/docs.json'),
            File::get($file)
        );

        // The standalone preset adds a "try it out" footer; keep it but make
        // the authorize button the obvious entry point.
        $js = str_replace(
            'layout: "StandaloneLayout"',
            'layout: "StandaloneLayout", persistAuthorization: true, tryItOutEnabled: false, docExpansion: "list"',
            $js
        );

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);
    }

    /**
     * Serves the bundled Swagger UI assets so the viewer works without a
     * third-party CDN.
     */
    public function asset(Request $request, string $file): BinaryFileResponse|Response
    {
        $root = realpath(base_path(self::ASSET_ROOT));

        if ($root === false) {
            abort(404);
        }

        $target = realpath($root.DIRECTORY_SEPARATOR.$file);

        // Reject anything resolving outside the asset directory.
        if ($target === false || ! str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        if (! is_file($target)) {
            abort(404);
        }

        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'map' => 'application/json',
            'html' => 'text/html',
        ];

        return response()->file($target, [
            'Content-Type' => $types[strtolower(pathinfo($target, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
        ]);
    }
}
