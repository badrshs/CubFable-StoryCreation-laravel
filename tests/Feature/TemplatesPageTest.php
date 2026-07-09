<?php

namespace Tests\Feature;

use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemplatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_library_wall_receives_templates_with_facets(): void
    {
        Template::factory()->create(['theme' => 'forest', 'subjects' => ['animals', 'nature']]);
        Template::factory()->create(['theme' => 'space', 'subjects' => ['rockets']]);

        $this->withoutVite()
            ->get(route('templates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('templates')
                ->has('templates', 2)
                ->has('templates.0.subjects')
                ->where('themes', ['forest', 'space']));
    }
}
