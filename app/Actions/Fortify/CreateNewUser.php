<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * How many accounts one network address may create per hour. Each
     * registration sends a verification email, so an unthrottled endpoint
     * would let a script drain the daily mail quota.
     */
    private const MAX_REGISTRATIONS_PER_IP_PER_HOUR = 3;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // The Fortify registration routes are registered from the env value at
        // boot, so the admin's runtime "registration closed" setting must be
        // enforced here or closing registration would only hide the buttons.
        abort_unless((bool) config('cubfable.registration_open'), 403, __('Registration is currently closed.'));

        $rateKey = 'registrations:'.(ClientIp::from(request()) ?? 'unknown');

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_REGISTRATIONS_PER_IP_PER_HOUR)) {
            throw ValidationException::withMessages([
                'email' => __('Too many accounts were created from your network. Please try again later.'),
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            // Disposable inboxes only at registration; existing accounts keep
            // whatever email they signed up with when editing their profile.
            'email' => [...$this->emailRules(), 'indisposable'],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Count only successful registrations, so typos and validation
        // errors never lock a legitimate visitor out.
        RateLimiter::hit($rateKey, 3600);

        return $user;
    }
}
