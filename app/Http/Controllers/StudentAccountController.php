<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentAccountResource;
use App\Models\Student;
use App\Support\StudentAccountCreator;
use App\Support\TemporaryPasswordGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentAccountController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    public const ACCOUNT_EXISTS_MESSAGE = 'This student already has a mobile account.';

    public const NO_ACCOUNT_MESSAGE = 'This student has no mobile account.';

    public function showAccount(int $id): JsonResponse
    {
        return response()->json(['account' => StudentAccountResource::make(Student::with('user')->findOrFail($id))]);
    }

    public function createAccount(int $id): JsonResponse
    {
        [$student, $plainPassword] = DB::transaction(function () use ($id) {
            $student = $this->lockStudent($id);

            if ($student->user_id) {
                throw ValidationException::withMessages(['account' => self::ACCOUNT_EXISTS_MESSAGE]);
            }

            return [$student, (new StudentAccountCreator($student))->create()];
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'account' => StudentAccountResource::make($student),
            'temporary_password' => $plainPassword,
        ], Response::HTTP_CREATED);
    }

    public function resetPassword(int $id): JsonResponse
    {
        [$student, $plainPassword] = DB::transaction(function () use ($id) {
            $student = $this->lockAccountUser($id);
            $plainPassword = TemporaryPasswordGenerator::generate();
            $student->user->forceFill(['password' => $plainPassword, 'must_change_password' => true])->save();
            $student->user->tokens()->delete();

            return [$student, $plainPassword];
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'account' => StudentAccountResource::make($student),
            'temporary_password' => $plainPassword,
        ]);
    }

    public function deactivateAccount(int $id): JsonResponse
    {
        $student = DB::transaction(function () use ($id) {
            $student = $this->lockAccountUser($id);
            $student->user->forceFill(['is_active' => false])->save();
            $student->user->tokens()->delete();

            return $student;
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['account' => StudentAccountResource::make($student)]);
    }

    public function reactivateAccount(int $id): JsonResponse
    {
        $student = DB::transaction(function () use ($id) {
            $student = $this->lockAccountUser($id);
            $student->user->forceFill(['is_active' => true])->save();

            return $student;
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['account' => StudentAccountResource::make($student)]);
    }

    private function lockStudent(int $id): Student
    {
        return Student::lockForUpdate()->findOrFail($id);
    }

    private function lockAccountUser(int $id): Student
    {
        $student = $this->lockStudent($id);

        if (! $student->user_id) {
            throw ValidationException::withMessages(['account' => self::NO_ACCOUNT_MESSAGE]);
        }

        return $student->load('user');
    }
}
