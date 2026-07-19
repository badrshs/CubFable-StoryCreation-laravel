<?php

namespace Tests\Feature\Auth;

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

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('books.index', absolute: false));
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
