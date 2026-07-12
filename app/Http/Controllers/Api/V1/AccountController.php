<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeleteAccountRequest;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    /**
     * Permanently delete the account (required by the App Store). Tokens are
     * revoked explicitly because personal access tokens have no FK cascade;
     * books, characters, and orders cascade at the database level.
     */
    public function destroy(DeleteAccountRequest $request): Response
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
