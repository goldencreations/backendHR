<?php

use App\Http\Controllers\Api\AuthController;
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

    // Phase 3 onward: documents, employees, leave, payroll.
});
