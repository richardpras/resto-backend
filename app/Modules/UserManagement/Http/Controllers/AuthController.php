<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\UserManagement\Http\Requests\LoginRequest;
use App\Modules\UserManagement\Http\Requests\UpdateScreenPinRequest;
use App\Modules\UserManagement\Http\Requests\VerifyScreenPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();
        if ($user === null || ! Hash::check((string) $request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $tokenResult = $user->createToken('api-access');
        $tokenModel = $tokenResult->getToken();

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'accessToken' => $tokenResult->accessToken,
                'tokenType' => $tokenResult->tokenType ?? 'Bearer',
                /** OAuth2-style lifetime in seconds (Passport personal access token). Mobile clients should refresh before expiry. */
                'expiresIn' => $tokenResult->expiresIn ?? null,
                'expiresAt' => $tokenModel?->expires_at?->toIso8601String(),
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions']);

        $permissionCodes = $user->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('code')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pinSet' => filled($user->pin_hash),
                'roles' => $user->roles->map(fn ($role) => [
                    'id' => (int) $role->id,
                    'name' => $role->name,
                ])->values()->all(),
                'permissionCodes' => $permissionCodes,
            ],
        ]);
    }

    public function verifyScreenPin(VerifyScreenPinRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! filled($user->pin_hash)) {
            return response()->json([
                'message' => 'Screen PIN is not set for this user.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! Hash::check($request->validated('pin'), $user->pin_hash)) {
            return response()->json([
                'message' => 'Incorrect PIN.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'PIN verified.',
        ]);
    }

    public function updateScreenPin(UpdateScreenPinRequest $request): JsonResponse
    {
        $user = $request->user();

        if (filled($user->pin_hash)) {
            $current = $request->validated('current_pin');
            if ($current === null || ! Hash::check($current, $user->pin_hash)) {
                return response()->json([
                    'message' => 'Current PIN is required and must be correct to change your PIN.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $user->pin_hash = $request->validated('pin');
        $user->save();

        return response()->json([
            'message' => 'Screen PIN updated.',
            'data' => [
                'pinSet' => true,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->token();
        if ($token !== null) {
            $token->revoke();
        }

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
