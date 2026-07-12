<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Exchange email + password for a personal access token.
     *
     * Credentials are checked directly instead of via Auth::attempt, which
     * targets the session guard. Two-factor and passkeys are web-only in v1;
     * token logins deliberately skip the 2FA challenge.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', Str::lower($request->string('email')->toString()))
            ->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken($request->string('deviceName')->toString())->plainTextToken;

        return UserResource::make($user)
            ->additional(['token' => $token])
            ->response($request);
    }
}
