<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('api-auth');
    }

    public function test_forgot_password_sends_a_reset_link()
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.forgot-password'), [
            'email' => $user->email,
        ]);

        $response->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_response_is_identical_for_unknown_emails()
    {
        Notification::fake();

        $user = User::factory()->create();

        $knownResponse = $this->postJson(route('api.v1.auth.forgot-password'), [
            'email' => $user->email,
        ]);

        RateLimiter::clear('api-auth');

        $unknownResponse = $this->postJson(route('api.v1.auth.forgot-password'), [
            'email' => 'nobody@example.com',
        ]);

        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));
        Notification::assertCount(1);
    }
}
