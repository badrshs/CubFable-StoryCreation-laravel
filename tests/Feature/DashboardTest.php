<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_route_redirects_to_the_books_gallery(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('books.index', absolute: false));
    }

    public function test_guests_following_the_redirect_are_sent_to_login(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('books.index', absolute: false));

        $this->get(route('books.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_land_on_their_gallery(): void
    {
        $user = User::factory()->create();

        $this->withoutVite();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('books.index', absolute: false));

        $this->actingAs($user)
            ->get(route('books.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('gallery')
                ->has('books', 0));
    }
}
