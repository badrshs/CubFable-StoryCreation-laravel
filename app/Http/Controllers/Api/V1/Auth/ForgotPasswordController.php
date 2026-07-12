<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * Send a password reset link. The response is identical whether or not
     * the email exists, so accounts cannot be enumerated. The link opens the
     * existing web reset page; the user then signs back into the app.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::broker('users')->sendResetLink([
            'email' => Str::lower($request->string('email')->toString()),
        ]);

        return response()->json([
            'message' => __('If an account exists for that email address, a password reset link has been sent.'),
        ]);
    }
}
