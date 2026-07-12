<?php

namespace Tests\Feature\Api;

use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_are_public_and_use_the_shared_camel_case_shape()
    {
        $template = Template::factory()->create([
            'title' => 'The Lantern in Whispering Wood',
            'theme' => 'forest',
            'page_count' => 8,
        ]);

        $response = $this->getJson(route('api.v1.templates.index'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $template->id)
            ->assertJsonPath('data.0.title', 'The Lantern in Whispering Wood')
            ->assertJsonPath('data.0.pageCount', 8)
            ->assertJsonStructure([
                'data' => [['id', 'title', 'description', 'theme', 'coverImageUrl', 'pageCount', 'ageMin', 'ageMax', 'lifeLessons', 'subjects']],
            ]);
    }

    public function test_templates_include_a_sorted_unique_theme_facet()
    {
        Template::factory()->create(['theme' => 'space']);
        Template::factory()->create(['theme' => 'forest']);
        Template::factory()->create(['theme' => 'space']);

        $response = $this->getJson(route('api.v1.templates.index'));

        $response->assertOk()->assertJsonPath('meta.themes', ['forest', 'space']);
    }
}
