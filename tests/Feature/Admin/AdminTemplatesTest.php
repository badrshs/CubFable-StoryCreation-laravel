<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'The Moonlit Garden',
            'description' => 'A gentle night-time adventure among glowing flowers.',
            'theme' => 'garden',
            'age_min' => 3,
            'age_max' => 7,
            'page_count' => 6,
            'cover_image_url' => '',
            'life_lessons' => ['Kindness'],
            'art_styles' => ['watercolor', 'storybook'],
            'subjects' => ['flowers', 'night'],
            'fonts' => ['classic', 'playful'],
            'image_prompt' => 'A moonlit garden with glowing flowers.',
            ...$overrides,
        ];
    }

    public function test_the_index_lists_and_searches_templates(): void
    {
        Template::factory()->create(['title' => 'Moon Voyage']);
        Template::factory()->create(['title' => 'Deep Sea Friends']);

        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/templates?search=Moon')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/templates/index')
                ->has('templates.data', 1)
                ->where('templates.data.0.title', 'Moon Voyage'));
    }

    public function test_a_cover_can_be_generated_from_the_image_prompt(): void
    {
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        $template = Template::factory()->create([
            'title' => 'Cover Gen Test Template',
            'cover_image_url' => 'data:image/svg+xml;base64,cGxhY2Vob2xkZXI=',
            'image_prompt' => 'A cheerful watercolor meadow with a rainbow.',
        ]);

        // A real (tiny) PNG so the JPEG transcode has valid bytes.
        $png = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($png);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($png);

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($pngBytes)]],
            ]),
        ]);

        $coverPath = public_path('images/templates/cover-gen-test-template.jpg');
        File::delete($coverPath);

        $this->actingAs($this->admin())
            ->post("/admin/templates/{$template->id}/generate-cover")
            ->assertRedirect();

        $this->assertFileExists($coverPath);
        $this->assertSame('/images/templates/cover-gen-test-template.jpg', $template->refresh()->cover_image_url);
        // The stored file is a JPEG, matching every hand-made cover.
        $this->assertStringStartsWith("\xFF\xD8", (string) file_get_contents($coverPath));
        $this->assertDatabaseHas('ai_usage', ['provider' => 'openai', 'kind' => 'image', 'book_id' => null]);

        File::delete($coverPath);
    }

    public function test_a_cover_cannot_be_generated_without_an_image_prompt(): void
    {
        $template = Template::factory()->create(['image_prompt' => '']);

        $this->actingAs($this->admin())
            ->from('/admin/templates')
            ->post("/admin/templates/{$template->id}/generate-cover")
            ->assertSessionHasErrors('template');
    }

    public function test_non_admins_cannot_generate_covers(): void
    {
        $template = Template::factory()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post("/admin/templates/{$template->id}/generate-cover")
            ->assertNotFound();
    }

    public function test_a_template_can_be_created(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/templates', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('templates', ['title' => 'The Moonlit Garden', 'page_count' => 6]);
        $this->assertSame(['watercolor', 'storybook'], Template::query()->where('title', 'The Moonlit Garden')->first()->art_styles);
    }

    public function test_page_count_respects_the_admin_configured_bounds(): void
    {
        config()->set('cubfable.pages_min', 5);
        config()->set('cubfable.pages_max', 8);

        $this->actingAs($this->admin())
            ->post('/admin/templates', $this->payload(['page_count' => 12]))
            ->assertSessionHasErrors('page_count');

        $this->actingAs($this->admin())
            ->post('/admin/templates', $this->payload(['page_count' => 4]))
            ->assertSessionHasErrors('page_count');

        $this->assertDatabaseMissing('templates', ['title' => 'The Moonlit Garden']);
    }

    public function test_unknown_art_styles_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/templates', $this->payload(['art_styles' => ['oil-paint']]))
            ->assertSessionHasErrors('art_styles.0');
    }

    public function test_a_template_can_be_updated(): void
    {
        $template = Template::factory()->create(['title' => 'Old Title', 'page_count' => 5]);

        $this->actingAs($this->admin())
            ->put("/admin/templates/{$template->id}", $this->payload(['title' => 'New Title', 'page_count' => 7]))
            ->assertRedirect();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'title' => 'New Title', 'page_count' => 7]);
    }

    public function test_a_template_with_books_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        Book::factory()->for($user)->for($template)->create();

        $this->actingAs($this->admin())
            ->from("/admin/templates/{$template->id}/edit")
            ->delete("/admin/templates/{$template->id}")
            ->assertSessionHasErrors('template');

        $this->assertDatabaseHas('templates', ['id' => $template->id]);
    }

    public function test_an_unused_template_can_be_deleted(): void
    {
        $template = Template::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/templates/{$template->id}")
            ->assertRedirect(route('admin.templates'));

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_non_admins_get_404(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $template = Template::factory()->create();

        $this->actingAs($user)->get('/admin/templates')->assertNotFound();
        $this->actingAs($user)->put("/admin/templates/{$template->id}", $this->payload())->assertNotFound();
    }
}
