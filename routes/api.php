<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchoolYearController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::prefix('mis')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'role:'.implode(',', Role::STAFF_ROLES)])->group(function () {
        Route::get('/user', [AuthController::class, 'fetchCurrentUser']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/school-years/get-active', [SchoolYearController::class, 'fetchActiveSchoolYear']);
    });

    Route::middleware(['auth:sanctum', 'role:'.Role::ADMIN])->group(function () {
        Route::put('/school-years/set-active/{id}', [SchoolYearController::class, 'setActiveSchoolYear'])->whereNumber('id');
        Route::get('/school-years', [SchoolYearController::class, 'listSchoolYears']);
        Route::post('/school-years', [SchoolYearController::class, 'createSchoolYear']);
        Route::put('/school-years/{id}', [SchoolYearController::class, 'updateSchoolYear'])->whereNumber('id');
        Route::delete('/school-years/{id}', [SchoolYearController::class, 'deleteSchoolYear'])->whereNumber('id');
    });
});
