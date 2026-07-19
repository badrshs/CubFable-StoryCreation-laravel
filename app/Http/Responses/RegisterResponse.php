<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After registration, land new users on the email-verification notice (which
 * offers resend and an explicit "skip for now") instead of dropping them into
 * the app as if nothing were pending.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if (
            Features::enabled(Features::emailVerification())
            && $user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()
        ) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(Fortify::redirects('register'));
    }
}
