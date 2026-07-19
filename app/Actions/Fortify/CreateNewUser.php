<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

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

        Validator::make($input, [
            ...$this->profileRules(),
            // Disposable inboxes only at registration; existing accounts keep
            // whatever email they signed up with when editing their profile.
            'email' => [...$this->emailRules(), 'indisposable'],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
