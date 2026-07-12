<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdatePasswordRequest;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    /**
     * Change the password and revoke every other device's token so a stolen
     * session cannot outlive a password change.
     */
    public function update(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();

        $user->update([
            'password' => $request->string('password')->toString(),
        ]);

        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $user->tokens()->whereKeyNot($currentToken->getKey())->delete();
        }

        return response()->noContent();
    }
}
