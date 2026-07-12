<?php

namespace Tests\Feature;

use App\Models\Template;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_curated_catalogue(): void
    {
        $this->seed(TemplateSeeder::class);

        $this->assertSame(18, Template::query()->count());
        $this->assertSame(
            [[2, 4], [5, 7], [8, 10]],
            Template::query()
                ->select(['age_min', 'age_max'])
                ->distinct()
                ->orderBy('age_min')
                ->get()
                ->map(fn (Template $template): array => [$template->age_min, $template->age_max])
                ->all(),
        );
    }

    public function test_reseeding_is_idempotent_and_refreshes_seed_owned_fields(): void
    {
        $this->seed(TemplateSeeder::class);

        $template = Template::query()->where('title', 'Goodnight, Little Moon')->firstOrFail();
        $template->update(['description' => 'Changed', 'page_count' => 99]);

        $this->seed(TemplateSeeder::class);

        $this->assertSame(18, Template::query()->count());
        $this->assertSame(6, $template->fresh()->page_count);
        $this->assertNotSame('Changed', $template->fresh()->description);
    }

    public function test_seeding_removes_an_unreferenced_template_outside_the_catalogue(): void
    {
        Template::factory()->create(['title' => 'Retired Idea']);

        $this->seed(TemplateSeeder::class);

        $this->assertDatabaseMissing('templates', ['title' => 'Retired Idea']);
    }

    public function test_covers_are_checked_in_art_or_valid_svg_placeholders(): void
    {
        $this->seed(TemplateSeeder::class);

        Template::query()->each(function (Template $template): void {
            $cover = $template->cover_image_url;

            if (str_starts_with($cover, '/images/templates/')) {
                $this->assertFileExists(public_path(ltrim($cover, '/')));

                return;
            }

            $this->assertStringStartsWith('data:image/svg+xml;base64,', $cover);
            $svg = base64_decode(Str::after($cover, 'base64,'), true);
            $this->assertNotFalse($svg);
            $this->assertStringContainsString('</svg>', $svg);
        });
    }

    public function test_catalogue_fields_are_complete_and_age_appropriate(): void
    {
        $this->seed(TemplateSeeder::class);

        Template::query()->each(function (Template $template): void {
            $this->assertContains([$template->age_min, $template->age_max], [[2, 4], [5, 7], [8, 10]]);
            $this->assertNotEmpty($template->description);
            $this->assertNotEmpty($template->life_lessons);
            $this->assertNotEmpty($template->art_styles);
            $this->assertNotEmpty($template->subjects);
            $this->assertNotEmpty($template->fonts);
            $this->assertNotEmpty($template->image_prompt);
        });
    }
}
