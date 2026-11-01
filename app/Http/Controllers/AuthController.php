<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TOKEN_NAME = 'mis';

    public function login(LoginUserRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // same error for unknown email and wrong password so the response does not reveal which accounts exist
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $plainTextToken = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        return response()->json([
            'token' => $plainTextToken,
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function fetchCurrentUser(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()->load('roles')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
