<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeMobilePasswordRequest;
use App\Http\Requests\MobileLoginRequest;
use App\Http\Resources\MobileStudentResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    private const STUDENT_RELATIONS = ['currentEnrollments.section.gradeLevel', 'currentEnrollments.section.schoolYear'];

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $student = Student::with('user')->where('lrn', $request->validated('lrn'))->first();
        $user = $student?->user;

        // same error for an unknown LRN, a student without an account, a wrong password and a non-student user so the response does not reveal which exist
        if (! $user || ! Hash::check($request->validated('password'), $user->password) || ! $user->hasAnyRole(Role::MOBILE_ROLES)) {
            throw ValidationException::withMessages(['lrn' => __('auth.failed')]);
        }

        // checked after the password so the deactivated notice never confirms an account to someone without its credentials
        if (! $user->is_active) {
            throw ValidationException::withMessages(['lrn' => AuthController::DEACTIVATED_MESSAGE]);
        }

        $plainTextToken = $user->createToken($request->validated('device_name'))->plainTextToken;

        return response()->json(['token' => $plainTextToken, ...$this->buildSessionBody($user)]);
    }

    public function fetchProfile(Request $request): JsonResponse
    {
        return response()->json($this->buildSessionBody($request->user()));
    }

    public function changePassword(ChangeMobilePasswordRequest $request): JsonResponse
    {
        $request->user()->forceFill(['password' => $request->validated('password'), 'must_change_password' => false])->save();

        return response()->json($this->buildSessionBody($request->user()));
    }

    /** @return array{user: UserResource, student: MobileStudentResource, must_change_password: bool} */
    private function buildSessionBody(User $user): array
    {
        $student = $user->student()->firstOrFail()->load(self::STUDENT_RELATIONS);

        return [
            'user' => UserResource::make($user->load('roles')),
            'student' => MobileStudentResource::make($student),
            'must_change_password' => $user->must_change_password,
        ];
    }
}
