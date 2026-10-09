<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeAvatarController;
use App\Http\Controllers\Api\EmployeeDocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Registered through the `api` file in bootstrap/app.php, which Laravel
| already prefixes with /api, so paths here must not repeat it.
|
| The token is issued by POST /api/auth/login and returned to the frontend
| at https://hr.goldencreations.online to send as a Bearer header.
|
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    Route::put('auth/password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('api.auth.password');

    /*
    | Documents. Reads are open to any authenticated caller because the
    | policy restricts them to HR or the owning employee; writes are HR only.
    | Downloads stream through PHP after that check, so no upload is ever
    | reachable by a static URL.
    */
    Route::get('files/{document}/download', [EmployeeDocumentController::class, 'download'])
        ->name('api.files.download');

    Route::get('documents', [EmployeeDocumentController::class, 'index'])
        ->name('api.documents.index');

    Route::get('documents/{document}', [EmployeeDocumentController::class, 'show'])
        ->name('api.documents.show');

    Route::get('employees/{employee}/avatar', [EmployeeAvatarController::class, 'show'])
        ->name('api.employees.avatar');

    Route::middleware('hr')->group(function () {
        Route::post('documents', [EmployeeDocumentController::class, 'store'])
            ->name('api.documents.store');

        Route::post('documents/{document}/replace', [EmployeeDocumentController::class, 'replace'])
            ->name('api.documents.replace');

        Route::delete('documents/{document}', [EmployeeDocumentController::class, 'destroy'])
            ->name('api.documents.destroy');

        Route::post('employees/{employee}/avatar', [EmployeeAvatarController::class, 'store'])
            ->name('api.employees.avatar.store');
    });
});
