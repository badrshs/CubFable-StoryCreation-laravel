<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register_and_land_on_the_verification_notice()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_registration_is_rejected_when_closed_by_the_runtime_setting()
    {
        config(['cubfable.registration_open' => false]);

        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registrations_from_one_ip_are_rate_limited()
    {
        foreach (range(1, 3) as $i) {
            $this->post(route('register.store'), [
                'name' => "Test User {$i}",
                'email' => "test{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
            $this->post(route('logout'));
        }

        $response = $this->post(route('register.store'), [
            'name' => 'Test User 4',
            'email' => 'test4@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertInvalid(['email']);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_verification_email_resend_is_rate_limited()
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    }

    public function test_registration_open_shared_prop_reflects_the_runtime_setting()
    {
        config(['cubfable.registration_open' => false]);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('registrationOpen', false));

        config(['cubfable.registration_open' => true]);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('registrationOpen', true));
    }
}
