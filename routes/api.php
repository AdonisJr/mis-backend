<?php

use App\Http\Controllers\AuthController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::prefix('mis')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'role:'.implode(',', Role::STAFF_ROLES)])->group(function () {
        Route::get('/user', [AuthController::class, 'fetchCurrentUser']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
