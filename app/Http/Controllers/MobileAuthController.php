<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeMobilePasswordRequest;
use App\Http\Requests\MobileLoginRequest;
use App\Http\Resources\MobileChildResource;
use App\Http\Resources\MobileStudentResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumberNormaliser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(MobileLoginRequest $request): JsonResponse
    {
        $identifierField = $request->resolveIdentifierField();
        $user = $this->findLoginUser($request->resolveIdentifier());

        // same error for an unknown identifier, an account-less student, a wrong password and a non-mobile user so the response does not reveal which exist
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([$identifierField => __('auth.failed')]);
        }

        // checked after the password so the deactivated notice never confirms an account to someone without its credentials
        if (! $user->is_active) {
            throw ValidationException::withMessages([$identifierField => AuthController::DEACTIVATED_MESSAGE]);
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

    public function listChildren(Request $request): JsonResponse
    {
        return response()->json(['children' => $this->buildChildList($request->user())]);
    }

    private function findLoginUser(string $identifier): ?User
    {
        $mobileNumber = MobileNumberNormaliser::normalise($identifier);

        if (MobileNumberNormaliser::isMobileNumber($mobileNumber)) {
            return User::parentAccounts()->where('mobile_number', $mobileNumber)->first();
        }

        $student = Student::with('user')->where('lrn', $identifier)->first();

        return $student?->user?->hasAnyRole([Role::STUDENT]) ? $student->user : null;
    }

    /** @return array<string, mixed> */
    private function buildSessionBody(User $user): array
    {
        $user->load('roles');

        if ($user->hasRole(Role::PARENT)) {
            return $this->buildParentSessionBody($user);
        }

        $student = $user->student()->firstOrFail()->load(MobileStudentResource::RELATIONS);

        return [
            'user' => UserResource::make($user),
            'student' => MobileStudentResource::make($student),
            'must_change_password' => $user->must_change_password,
        ];
    }

    /** @return array<string, mixed> */
    private function buildParentSessionBody(User $user): array
    {
        return [
            'user' => UserResource::make($user),
            'student' => null,
            'must_change_password' => $user->must_change_password,
            'children' => $this->buildChildList($user),
        ];
    }

    private function buildChildList(User $user): AnonymousResourceCollection
    {
        return MobileChildResource::collection($user->children()->with(MobileStudentResource::RELATIONS)->get());
    }
}
