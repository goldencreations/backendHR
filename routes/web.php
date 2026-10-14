<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Temporary SPA hosting: a static frontend export is copied into
    // public/ so the API origin can serve the app until the dedicated
    // frontend vhost is restored. Nginx serves /index.html directly;
    // this covers the bare / path if it ever reaches PHP.
    $spa = public_path('index.html');

    if (is_file($spa)) {
        return response()->file($spa);
    }

    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| API documentation
|--------------------------------------------------------------------------
|
| Swagger UI plus the raw OpenAPI document. Public, because the
| documentation is not sensitive and a locked viewer helps nobody; every
| endpoint it describes still requires a bearer token.
|
| The spec is generated from the registered route table, so a path that
| exists is documented and one that is not documented does not exist.
|
*/

Route::get('api-docs', [ApiDocsController::class, 'ui'])->name('api-docs');
Route::get('api/docs.json', [ApiDocsController::class, 'spec'])->name('api.docs.spec');
Route::get('api/docs/assets/swagger-initializer.js', [ApiDocsController::class, 'initializer'])
    ->name('api.docs.initializer');
Route::get('api/docs/assets/{file}', [ApiDocsController::class, 'asset'])->name('api.docs.asset');
