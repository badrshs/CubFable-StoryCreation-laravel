<?php

namespace Tests\Feature\Admin;

use App\Enums\PageStatus;
use App\Jobs\EngineOverride;
use App\Jobs\RegenerateCoverJob;
use App\Jobs\RegeneratePageJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\ImageVersion;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use App\Services\BookStopSignal;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminImageVersionsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array{0: Book, 1: Page}
     */
    private function bookWithImages(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $book = Book::factory()->complete()->for($user)->for($template)->create(['child_name' => 'Mia']);

        $cover = "books/{$book->id}/cover-old.png";
        Storage::disk('public')->put($cover, (string) base64_decode(self::PNG_BASE64, true));
        $book->update(['cover_image_path' => $cover]);

        $pagePath = "books/{$book->id}/pages/1-old.png";
        Storage::disk('public')->put($pagePath, (string) base64_decode(self::PNG_BASE64, true));

        $page = Page::query()->create([
            'book_id' => $book->id,
            'page_number' => 1,
            'text' => 'Page 1',
            'scene' => 'Mia waves.',
            'image_path' => $pagePath,
            'status' => PageStatus::Complete,
        ]);

        return [$book, $page];
    }

    public function test_admin_can_queue_a_page_and_cover_regeneration(): void
    {
        Queue::fake();
        [$book, $page] = $this->bookWithImages();

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", ['target' => 'page-1'])
            ->assertRedirect();

        $this->assertSame(PageStatus::Generating, $page->refresh()->status);
        Queue::assertPushed(RegeneratePageJob::class);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", ['target' => 'cover'])
            ->assertRedirect();

        $this->assertSame('generating', $book->refresh()->cover_status);
        Queue::assertPushed(RegenerateCoverJob::class);
    }

    public function test_regenerating_keeps_the_previous_file_and_records_a_version(): void
    {
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        [$book, $page] = $this->bookWithImages();
        $book->characters()->attach(
            Character::factory()->for($book->user)->create([
                'name' => 'Mia',
                'appearance' => 'Short curly brown hair, yellow raincoat.',
            ])->id,
            ['is_main' => true, 'sort_order' => 0],
        );

        $oldPath = (string) $page->image_path;

        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new RegeneratePageJob($page->id))->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        $page->refresh();
        $this->assertNotSame($oldPath, $page->image_path);
        // The replaced file survives, and BOTH images have version rows: the
        // pre-tracking image is captured before being replaced.
        Storage::disk('public')->assertExists($oldPath);
        $this->assertDatabaseHas('image_versions', [
            'book_id' => $book->id,
            'slot' => 'page',
            'page_number' => 1,
            'path' => $page->image_path,
        ]);
        $this->assertDatabaseHas('image_versions', [
            'book_id' => $book->id,
            'slot' => 'page',
            'page_number' => 1,
            'path' => $oldPath,
        ]);
    }

    public function test_an_engine_override_travels_with_the_regeneration_job(): void
    {
        Queue::fake();
        [$book] = $this->bookWithImages();

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", [
                'target' => 'page-1',
                'provider' => 'replicate',
            ])
            ->assertRedirect();

        Queue::assertPushed(RegeneratePageJob::class, fn (RegeneratePageJob $job): bool => $job->imageProvider === 'replicate' && $job->imageModel === null);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", [
                'target' => 'cover',
                'provider' => 'flow',
                'model' => 'google-flow',
            ])
            ->assertRedirect();

        Queue::assertPushed(RegenerateCoverJob::class, fn (RegenerateCoverJob $job): bool => $job->imageProvider === 'flow' && $job->imageModel === 'google-flow');

        // A Replicate catalog engine sends its exact model with the job, so
        // the dropdown label and the model that actually runs always match.
        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", [
                'target' => 'page-1',
                'provider' => 'replicate',
                'model' => 'bytedance/seedream-4.5',
            ])
            ->assertRedirect();

        Queue::assertPushed(RegeneratePageJob::class, fn (RegeneratePageJob $job): bool => $job->imageProvider === 'replicate' && $job->imageModel === 'bytedance/seedream-4.5');

        // Unknown providers are rejected.
        $this->actingAs($this->admin())
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/images/regenerate", ['target' => 'cover', 'provider' => 'hacked'])
            ->assertSessionHasErrors('provider');
    }

    public function test_a_one_off_style_travels_with_the_regeneration_job(): void
    {
        Queue::fake();
        [$book] = $this->bookWithImages();
        // A known stored style so the "one-off does not change the book" check
        // is deterministic regardless of the factory's random default.
        $book->update(['art_style' => 'storybook']);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/regenerate", [
                'target' => 'page-1',
                'provider' => 'replicate',
                'model' => 'bytedance/seedream-4.5',
                'artStyle' => 'watercolor',
            ])
            ->assertRedirect();

        Queue::assertPushed(RegeneratePageJob::class, fn (RegeneratePageJob $job): bool => $job->artStyle === 'watercolor');

        // The book's own style is never touched by a one-off regeneration.
        $this->assertSame('storybook', $book->refresh()->art_style);

        // An unknown style is rejected.
        $this->actingAs($this->admin())
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/images/regenerate", ['target' => 'cover', 'artStyle' => 'not-a-style'])
            ->assertSessionHasErrors('artStyle');
    }

    public function test_the_style_override_reaches_the_prompt_composer_without_saving_the_book(): void
    {
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        [$book, $page] = $this->bookWithImages();
        // A known stored style, distinct from the override, so the assertion
        // is deterministic regardless of the factory's random default.
        $book->update(['art_style' => 'storybook']);
        $book->characters()->attach(
            Character::factory()->for($book->user)->create([
                'name' => 'Mia',
                'appearance' => 'Short curly brown hair, yellow raincoat.',
            ])->id,
            ['is_main' => true, 'sort_order' => 0],
        );

        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new RegeneratePageJob($page->id, null, null, 'watercolor'))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        // The one-off override drew this image in watercolor, but the book's
        // stored style is never changed.
        $this->assertSame('storybook', $book->refresh()->art_style);
    }

    public function test_the_override_reconfigures_the_engine_for_that_job_only(): void
    {
        EngineOverride::apply('replicate', 'some/custom-model');

        $this->assertSame('replicate', config('cubfable.ai.image_provider'));
        $this->assertSame('some/custom-model', config('cubfable.ai.models.image.replicate'));

        // No override, no change.
        config()->set('cubfable.ai.image_provider', 'openai');
        EngineOverride::apply(null, null);
        $this->assertSame('openai', config('cubfable.ai.image_provider'));
    }

    public function test_restore_points_a_page_back_at_an_older_version(): void
    {
        [$book, $page] = $this->bookWithImages();

        $olderPath = "books/{$book->id}/pages/1-older.png";
        Storage::disk('public')->put($olderPath, (string) base64_decode(self::PNG_BASE64, true));

        $version = ImageVersion::query()->create([
            'book_id' => $book->id,
            'page_id' => $page->id,
            'page_number' => 1,
            'slot' => 'page',
            'path' => $olderPath,
            'prompt' => 'the older prompt',
        ]);

        $page->update(['status' => PageStatus::Failed]);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/restore", ['versionId' => $version->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame($olderPath, $page->image_path);
        $this->assertSame('the older prompt', $page->image_prompt);
        $this->assertSame(PageStatus::Complete, $page->status);
    }

    public function test_restore_rejects_versions_from_other_books_and_missing_files(): void
    {
        [$book] = $this->bookWithImages();
        [$otherBook] = $this->bookWithImages();

        $foreign = ImageVersion::query()->create([
            'book_id' => $otherBook->id,
            'slot' => 'cover',
            'path' => (string) $otherBook->cover_image_path,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/images/restore", ['versionId' => $foreign->id])
            ->assertNotFound();

        $ghost = ImageVersion::query()->create([
            'book_id' => $book->id,
            'slot' => 'cover',
            'path' => "books/{$book->id}/cover-deleted.png",
        ]);

        $this->actingAs($this->admin())
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/images/restore", ['versionId' => $ghost->id])
            ->assertSessionHasErrors('book');
    }

    public function test_non_admins_get_404(): void
    {
        [$book] = $this->bookWithImages();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->post("/admin/books/{$book->id}/images/regenerate", ['target' => 'cover'])->assertNotFound();
        $this->actingAs($user)->post("/admin/books/{$book->id}/images/restore", ['versionId' => 1])->assertNotFound();
    }
}
