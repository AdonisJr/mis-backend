<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FeeItemController;
use App\Http\Controllers\FeeScheduleController;
use App\Http\Controllers\FeeScheduleCopyController;
use App\Http\Controllers\GradeLevelController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SectionRolloverController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TermController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::prefix('mis')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'role:'.implode(',', Role::STAFF_ROLES)])->group(function () {
        Route::get('/user', [AuthController::class, 'fetchCurrentUser']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/school-years/get-active', [SchoolYearController::class, 'fetchActiveSchoolYear']);
        Route::get('/school-years', [SchoolYearController::class, 'listSchoolYears']);
        Route::get('/students', [StudentController::class, 'listStudents']);
        Route::post('/students', [StudentController::class, 'createStudent']);
        Route::put('/students/{id}', [StudentController::class, 'updateStudent'])->whereNumber('id');
        Route::delete('/students/{id}', [StudentController::class, 'deleteStudent'])->whereNumber('id');
        Route::get('/grade-levels', [GradeLevelController::class, 'listGradeLevels']);
        Route::get('/programs', [ProgramController::class, 'listPrograms']);
        Route::get('/school-years/{id}/terms', [TermController::class, 'listTerms'])->whereNumber('id');
        Route::get('/terms/get-active', [TermController::class, 'fetchActiveTerm']);
        Route::get('/sections', [SectionController::class, 'listSections']);
        Route::get('/sections/{id}/rollover-preview', [SectionRolloverController::class, 'previewRollover'])->whereNumber('id');
        Route::post('/sections/{id}/rollover', [SectionRolloverController::class, 'rolloverSection'])->whereNumber('id');
        Route::put('/sections/{id}/complete', [SectionRolloverController::class, 'completeSection'])->whereNumber('id');
        Route::get('/enrollments', [EnrollmentController::class, 'listEnrollments']);
        Route::post('/enrollments', [EnrollmentController::class, 'enrollStudent']);
        Route::put('/enrollments/{id}/move', [EnrollmentController::class, 'moveEnrollment'])->whereNumber('id');
        Route::put('/enrollments/{id}/drop', [EnrollmentController::class, 'dropEnrollment'])->whereNumber('id');
        Route::put('/enrollments/{id}/transfer', [EnrollmentController::class, 'transferEnrollment'])->whereNumber('id');
        Route::put('/enrollments/{id}/complete', [EnrollmentController::class, 'completeEnrollment'])->whereNumber('id');
    });

    Route::middleware(['auth:sanctum', 'role:'.Role::ADMIN])->group(function () {
        Route::put('/school-years/set-active/{id}', [SchoolYearController::class, 'setActiveSchoolYear'])->whereNumber('id');
        Route::post('/school-years', [SchoolYearController::class, 'createSchoolYear']);
        Route::put('/school-years/{id}', [SchoolYearController::class, 'updateSchoolYear'])->whereNumber('id');
        Route::delete('/school-years/{id}', [SchoolYearController::class, 'deleteSchoolYear'])->whereNumber('id');
        Route::get('/employees', [EmployeeController::class, 'listEmployees']);
        Route::post('/employees', [EmployeeController::class, 'createEmployee']);
        Route::put('/employees/{id}', [EmployeeController::class, 'updateEmployee'])->whereNumber('id');
        Route::put('/employees/{id}/password', [EmployeeController::class, 'setEmployeePassword'])->whereNumber('id');
        Route::put('/employees/{id}/deactivate', [EmployeeController::class, 'deactivateEmployee'])->whereNumber('id');
        Route::put('/employees/{id}/reactivate', [EmployeeController::class, 'reactivateEmployee'])->whereNumber('id');
        Route::post('/programs', [ProgramController::class, 'createProgram']);
        Route::put('/programs/{id}', [ProgramController::class, 'updateProgram'])->whereNumber('id');
        Route::put('/programs/{id}/deactivate', [ProgramController::class, 'deactivateProgram'])->whereNumber('id');
        Route::put('/programs/{id}/reactivate', [ProgramController::class, 'reactivateProgram'])->whereNumber('id');
        Route::delete('/programs/{id}', [ProgramController::class, 'deleteProgram'])->whereNumber('id');
        Route::put('/terms/set-active/{id}', [TermController::class, 'setActiveTerm'])->whereNumber('id');
        Route::post('/school-years/{id}/terms', [TermController::class, 'createTerm'])->whereNumber('id');
        Route::put('/terms/{id}', [TermController::class, 'updateTerm'])->whereNumber('id');
        Route::delete('/terms/{id}', [TermController::class, 'deleteTerm'])->whereNumber('id');
        Route::post('/sections', [SectionController::class, 'createSection']);
        Route::put('/sections/{id}', [SectionController::class, 'updateSection'])->whereNumber('id');
        Route::delete('/sections/{id}', [SectionController::class, 'deleteSection'])->whereNumber('id');
        Route::get('/fee-items', [FeeItemController::class, 'listFeeItems']);
        Route::post('/fee-items', [FeeItemController::class, 'createFeeItem']);
        Route::put('/fee-items/{id}', [FeeItemController::class, 'updateFeeItem'])->whereNumber('id');
        Route::put('/fee-items/{id}/deactivate', [FeeItemController::class, 'deactivateFeeItem'])->whereNumber('id');
        Route::put('/fee-items/{id}/reactivate', [FeeItemController::class, 'reactivateFeeItem'])->whereNumber('id');
        Route::get('/fee-schedules/lookup', [FeeScheduleController::class, 'lookupFeeSchedule']);
        Route::post('/fee-schedules/copy', [FeeScheduleCopyController::class, 'copyFeeSchedules']);
        Route::get('/fee-schedules', [FeeScheduleController::class, 'listFeeSchedules']);
        Route::post('/fee-schedules', [FeeScheduleController::class, 'createFeeSchedule']);
        Route::get('/fee-schedules/{id}', [FeeScheduleController::class, 'showFeeSchedule'])->whereNumber('id');
        Route::put('/fee-schedules/{id}', [FeeScheduleController::class, 'updateFeeSchedule'])->whereNumber('id');
        Route::delete('/fee-schedules/{id}', [FeeScheduleController::class, 'deleteFeeSchedule'])->whereNumber('id');
    });
});
