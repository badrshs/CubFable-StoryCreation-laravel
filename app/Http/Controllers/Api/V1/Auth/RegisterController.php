<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /**
     * Register a new account and issue a personal access token for the device.
     */
    public function __invoke(Request $request, CreateNewUser $creator): JsonResponse
    {
        abort_unless((bool) config('cubfable.registration_open'), 403, __('Registration is currently closed.'));

        $request->validate([
            'deviceName' => ['required', 'string', 'max:255'],
        ]);

        $input = $request->all();
        $input['email'] = Str::lower((string) ($input['email'] ?? ''));

        $user = $creator->create($input);

        event(new Registered($user));

        $token = $user->createToken($request->string('deviceName')->toString())->plainTextToken;

        return UserResource::make($user)
            ->additional(['token' => $token])
            ->response($request)
            ->setStatusCode(201);
    }
}
