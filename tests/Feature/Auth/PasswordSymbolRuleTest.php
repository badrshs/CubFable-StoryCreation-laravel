<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PasswordSymbolRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dollar_sign_password_satisfies_production_symbol_rule(): void
    {
        // Same chain as production (AppServiceProvider), minus uncompromised() to avoid a network call.
        $rules = Password::min(12)->mixedCase()->letters()->numbers()->symbols();

        $password = 'pA2Sj3DQ$t9kkL4w$';

        $validator = Validator::make(
            ['password' => $password],
            ['password' => $rules],
        );

        $this->assertTrue(
            $validator->passes(),
            'Expected password to pass, got: '.$validator->errors()->first('password'),
        );
    }

    public function test_dollar_sign_matches_the_symbol_regex(): void
    {
        $this->assertSame(1, preg_match('/\p{Z}|\p{S}|\p{P}/u', '$'));
    }

    public function test_create_new_user_accepts_dollar_sign_password_under_production_rules(): void
    {
        // Force the exact production defaults (isProduction() is false in tests).
        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols());

        $password = 'pA2Sj3DQ$t9kkL4w$';

        try {
            $user = (new CreateNewUser)->create([
                'name' => 'Badr Aldeen Shek Salim',
                'email' => 'shs1bader@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ]);
        } catch (ValidationException $e) {
            $this->fail('Validation failed: '.json_encode($e->errors()));
        }

        $this->assertNotNull($user->id);
    }
}
