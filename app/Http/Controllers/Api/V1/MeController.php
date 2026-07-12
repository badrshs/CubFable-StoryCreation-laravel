<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The authenticated user's profile.
     */
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    /**
     * Update name/email. Changing the email invalidates the verification,
     * mirroring the web settings profile controller.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return UserResource::make($user);
    }
}
