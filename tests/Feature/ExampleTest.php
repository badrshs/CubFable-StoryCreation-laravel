<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_renders_with_aggregate_stats(): void
    {
        $this->withoutVite();

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('stats.totalBooks', 0)
                ->where('stats.completedBooks', 0));
    }
}
