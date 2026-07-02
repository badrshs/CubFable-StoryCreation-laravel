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

    public function test_seeding_creates_all_126_templates(): void
    {
        $this->seed(TemplateSeeder::class);

        $this->assertSame(126, Template::query()->count());
    }

    public function test_reseeding_is_idempotent(): void
    {
        $this->seed(TemplateSeeder::class);
        $this->seed(TemplateSeeder::class);

        $this->assertSame(126, Template::query()->count());
    }

    public function test_reseeding_syncs_covers_back_to_the_checked_in_art(): void
    {
        $this->seed(TemplateSeeder::class);

        $template = Template::query()->where('title', 'The Whispering Forest')->firstOrFail();
        $template->update(['cover_image_url' => 'https://example.com/manual-override.png']);

        $this->seed(TemplateSeeder::class);

        // Covers are seed-owned now: re-seeding always recomputes them so
        // newly checked-in real art syncs automatically.
        $this->assertSame('/images/templates/the-whispering-forest.jpg', $template->fresh()->cover_image_url);
    }

    public function test_reseeding_refreshes_seed_owned_fields(): void
    {
        $this->seed(TemplateSeeder::class);

        $template = Template::query()->where('title', 'The Whispering Forest')->firstOrFail();
        $template->update(['description' => 'A tampered description.', 'page_count' => 99]);

        $this->seed(TemplateSeeder::class);

        $template->refresh();
        $this->assertSame(
            'A gentle woodland adventure where your child befriends the shy creatures of an enchanted forest.',
            $template->description,
        );
        $this->assertSame(6, $template->page_count);
    }

    public function test_covers_are_checked_in_art_or_svg_placeholders(): void
    {
        $this->seed(TemplateSeeder::class);

        Template::query()->each(function (Template $template) {
            $cover = $template->cover_image_url;

            if (str_starts_with($cover, '/images/templates/')) {
                $this->assertFileExists(
                    public_path(ltrim($cover, '/')),
                    "Checked-in cover for [{$template->title}] is missing on disk.",
                );

                return;
            }

            $this->assertStringStartsWith('data:image/svg+xml;base64,', $cover);

            $svg = base64_decode(Str::after($cover, 'base64,'), true);

            $this->assertNotFalse($svg, "Cover for [{$template->title}] is not valid base64.");
            $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
            $this->assertStringContainsString('</svg>', $svg);
        });
    }

    public function test_json_array_fields_cast_to_arrays(): void
    {
        $this->seed(TemplateSeeder::class);

        $template = Template::query()->where('title', 'The Whispering Forest')->firstOrFail();

        $this->assertSame(['Kindness', 'Courage', 'Friendship'], $template->life_lessons);
        $this->assertSame(['watercolor', 'storybook', 'pencil-sketch'], $template->art_styles);
        $this->assertSame(['animals', 'nature', 'adventure'], $template->subjects);
        $this->assertSame(['playful', 'classic', 'handwritten'], $template->fonts);
    }
}
