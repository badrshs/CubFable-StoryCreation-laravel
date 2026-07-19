<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    private function enableTurnstile(): void
    {
        config([
            'services.turnstile.site_key' => 'test-site-key',
            'services.turnstile.secret_key' => 'test-secret-key',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(array $extra = []): array
    {
        return [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$extra,
        ];
    }

    public function test_everything_passes_through_when_turnstile_is_not_configured(): void
    {
        Http::fake();

        $this->post(route('register.store'), $this->registrationPayload());

        $this->assertAuthenticated();
        Http::assertNothingSent();
    }

    public function test_registration_without_a_token_is_rejected(): void
    {
        $this->enableTurnstile();

        $response = $this->post(route('register.store'), $this->registrationPayload());

        $response->assertInvalid(['turnstile']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_with_a_rejected_token_is_rejected(): void
    {
        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $response = $this->post(route('register.store'), $this->registrationPayload([
            'cf-turnstile-response' => 'bad-token',
        ]));

        $response->assertInvalid(['turnstile']);
        $this->assertGuest();
    }

    public function test_registration_with_a_valid_token_succeeds(): void
    {
        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->post(route('register.store'), $this->registrationPayload([
            'cf-turnstile-response' => 'good-token',
        ]));

        $this->assertAuthenticated();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'siteverify')
            && $request['response'] === 'good-token');
    }

    public function test_login_requires_a_valid_token(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertInvalid(['turnstile']);
        $this->assertGuest();
    }

    public function test_forgot_password_requires_a_valid_token(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();

        $response = $this->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $response->assertInvalid(['turnstile']);
    }

    public function test_cloudflare_outage_fails_open(): void
    {
        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->post(route('register.store'), $this->registrationPayload([
            'cf-turnstile-response' => 'any-token',
        ]));

        $this->assertAuthenticated();
    }
}
