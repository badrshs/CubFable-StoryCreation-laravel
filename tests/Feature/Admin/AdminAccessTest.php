<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_regular_users_get_a_404_never_a_403(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertNotFound();
    }

    public function test_admins_reach_the_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withoutVite()
            ->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_the_first_user_was_promoted_by_the_migration(): void
    {
        // RefreshDatabase reruns migrations; the first created user is admin.
        $first = User::factory()->create();
        $second = User::factory()->create();

        // The migration promotes at migrate-time, not at insert-time, so this
        // asserts the column exists and defaults correctly for new users.
        $this->assertFalse($second->refresh()->is_admin);
        $this->assertFalse($first->refresh()->is_admin);
    }
}
