<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class DisposableEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_with_a_disposable_email_domain_is_rejected(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'throwaway@mailinator.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertInvalid(['email']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_with_a_regular_email_domain_succeeds(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'parent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'parent@example.com']);
    }
}
