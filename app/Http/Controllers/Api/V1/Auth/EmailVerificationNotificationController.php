<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Resend the email verification link. The link lands on the existing web
     * verification route.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json(['message' => __('Email already verified.')]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => __('Verification link sent.')]);
    }
}
