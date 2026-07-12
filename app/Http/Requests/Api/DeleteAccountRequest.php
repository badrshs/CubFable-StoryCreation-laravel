<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class DeleteAccountRequest extends FormRequest
{
    /**
     * The current_password rule resolves the default web guard, which has no
     * user on token-authenticated routes, so the check is made explicitly.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! Hash::check((string) $value, (string) $this->user()?->password)) {
                        $fail(__('validation.current_password'));
                    }
                },
            ],
        ];
    }
}
