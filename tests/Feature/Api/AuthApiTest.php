<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('api-auth');
    }

    public function test_registration_creates_user_and_returns_token()
    {
        Event::fake([Registered::class]);

        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Aisha',
            'email' => 'Aisha@Example.com',
            'password' => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
            'deviceName' => 'iPhone 17',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Aisha')
            ->assertJsonPath('data.email', 'aisha@example.com')
            ->assertJsonPath('data.emailVerified', false);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', ['email' => 'aisha@example.com']);
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_is_rejected_when_registration_is_closed()
    {
        config()->set('cubfable.registration_open', false);

        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Aisha',
            'email' => 'aisha@example.com',
            'password' => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
            'deviceName' => 'iPhone 17',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_a_device_name()
    {
        $response = $this->postJson(route('api.v1.auth.register'), [
            'name' => 'Aisha',
            'email' => 'aisha@example.com',
            'password' => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('deviceName');
    }

    public function test_login_returns_token_for_valid_credentials()
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'deviceName' => 'Pixel 10',
        ]);

        $response->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 10',
        ]);
    }

    public function test_login_fails_with_wrong_password()
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
            'deviceName' => 'Pixel 10',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.v1.auth.login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
                'deviceName' => 'Pixel 10',
            ]);
        }

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'deviceName' => 'Pixel 10',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_logout_revokes_only_the_current_token()
    {
        $user = User::factory()->create();
        $keptToken = $user->createToken('other-device')->plainTextToken;
        $currentToken = $user->createToken('this-device')->plainTextToken;

        $response = $this->withToken($currentToken)->postJson(route('api.v1.auth.logout'));

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'other-device']);

        $this->withToken($keptToken)->getJson(route('api.v1.me.show'))->assertOk();
    }

    public function test_me_returns_the_authenticated_profile()
    {
        $user = User::factory()->create(['name' => 'Badr']);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.me.show'));

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Badr')
            ->assertJsonPath('data.emailVerified', true);
    }

    public function test_me_requires_authentication()
    {
        $this->getJson(route('api.v1.me.show'))->assertUnauthorized();
    }

    public function test_profile_update_resets_email_verification_on_email_change()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.me.update'), [
            'name' => 'New Name',
            'email' => 'new-address@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'new-address@example.com')
            ->assertJsonPath('data.emailVerified', false);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_profile_update_keeps_verification_when_email_is_unchanged()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.me.update'), [
            'name' => 'New Name',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('data.emailVerified', true);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_password_change_rejects_a_wrong_current_password()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'not-my-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_updates_password_and_revokes_other_tokens()
    {
        $user = User::factory()->create();
        $otherToken = $user->createToken('other-device')->plainTextToken;
        $currentToken = $user->createToken('this-device')->plainTextToken;

        $response = $this->withToken($currentToken)->putJson(route('api.v1.me.password'), [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'this-device']);

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)->getJson(route('api.v1.me.show'))->assertUnauthorized();
    }
}
