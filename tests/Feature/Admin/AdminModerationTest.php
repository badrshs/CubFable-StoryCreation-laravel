<?php

namespace Tests\Feature\Admin;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * A book with one flagged page (journaled attempts) and a flagged cover.
     *
     * @return array{0: Book, 1: Page}
     */
    private function flaggedBook(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 2]);
        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'child_name' => 'Lydia',
            'cover_flagged_at' => now(),
        ]);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'status' => PageStatus::Failed,
            'flagged_at' => now(),
            'scene' => 'Lydia stands near a tall, antique grandfather clock.',
            'art_direction' => [
                'shot' => 'wide establishing',
                'action' => 'Lydia stands near a tall, antique grandfather clock.',
                'expression' => 'curious',
                'detail' => 'a wooden bird on the pendulum',
            ],
        ]);

        ImagePrompt::query()->create([
            'book_id' => $book->id,
            'page_id' => $page->id,
            'purpose' => 'page',
            'attempt' => 1,
            'round' => 1,
            'variant' => 'original',
            'provider' => 'replicate',
            'model' => 'bytedance/seedream-5-pro',
            'prompt' => 'the original page prompt',
            'accepted' => false,
            'error' => 'flagged as sensitive (E005)',
        ]);

        return [$book, $page];
    }

    public function test_the_queue_lists_flagged_pages_and_covers_with_their_attempt_timeline(): void
    {
        [$book, $page] = $this->flaggedBook();

        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/moderation')
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('admin/moderation')
                ->has('items', 2)
                ->where('items.0.type', 'cover')
                ->where('items.0.bookId', $book->id)
                ->where('items.1.type', 'page')
                ->where('items.1.pageId', $page->id)
                ->where('items.1.pageNumber', 1)
                ->where('items.1.attempts.0.provider', 'replicate')
                ->where('items.1.attempts.0.error', 'flagged as sensitive (E005)')
                ->where('items.1.attempts.0.round', 1));
    }

    public function test_dismissing_clears_the_flags(): void
    {
        [$book, $page] = $this->flaggedBook();

        $this->actingAs($this->admin())
            ->post("/admin/moderation/pages/{$page->id}/dismiss")
            ->assertRedirect();

        $this->assertNull($page->refresh()->flagged_at);

        $this->actingAs($this->admin())
            ->post("/admin/moderation/books/{$book->id}/cover/dismiss")
            ->assertRedirect();

        $this->assertNull($book->refresh()->cover_flagged_at);
    }

    public function test_the_scene_of_a_flagged_page_can_be_reworded(): void
    {
        [, $page] = $this->flaggedBook();

        $this->actingAs($this->admin())
            ->put("/admin/moderation/pages/{$page->id}/scene", [
                'action' => 'Lydia stands near a tall antique standing clock.',
                'detail' => 'a wooden bird on the pendulum',
            ])
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('Lydia stands near a tall antique standing clock.', $page->art_direction['action']);
        $this->assertSame('Lydia stands near a tall antique standing clock. a wooden bird on the pendulum', $page->scene);
        // Untouched art direction keys survive the edit.
        $this->assertSame('wide establishing', $page->art_direction['shot']);
    }

    public function test_scene_edits_are_rejected_for_pages_that_are_not_flagged(): void
    {
        [, $page] = $this->flaggedBook();
        $page->update(['flagged_at' => null]);

        $this->actingAs($this->admin())
            ->put("/admin/moderation/pages/{$page->id}/scene", ['action' => 'anything'])
            ->assertNotFound();
    }

    public function test_the_queue_is_admin_only(): void
    {
        // Regular users get a 404, never a 403: the admin area is invisible.
        $this->actingAs(User::factory()->create())
            ->get('/admin/moderation')
            ->assertNotFound();
    }
}
