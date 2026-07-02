<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugPromptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_gets_the_full_prompt_journal_as_json(): void
    {
        [$user, $book, $page] = $this->bookWithJournal();

        $this->actingAs($user)
            ->getJson(route('debug.book-prompts', ['id' => $book->id]))
            ->assertOk()
            ->assertJsonPath('bookId', $book->id)
            ->assertJsonPath('count', 3)
            ->assertJsonPath('prompts.0.purpose', 'cover')
            ->assertJsonPath('prompts.0.variant', 'original')
            ->assertJsonPath('prompts.0.accepted', false)
            ->assertJsonPath('prompts.0.prompt', 'the original cover prompt')
            ->assertJsonPath('prompts.1.accepted', true)
            ->assertJsonPath('prompts.2.purpose', 'page')
            ->assertJsonPath('prompts.2.pageNumber', $page->page_number);
    }

    public function test_someone_elses_book_is_a_404(): void
    {
        [, $book] = $this->bookWithJournal();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('debug.book-prompts', ['id' => $book->id]))
            ->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [, $book] = $this->bookWithJournal();

        $this->get(route('debug.book-prompts', ['id' => $book->id]))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_the_endpoint_does_not_exist_in_production(): void
    {
        [$user, $book] = $this->bookWithJournal();

        $this->app['env'] = 'production';

        $this->actingAs($user)
            ->getJson(route('debug.book-prompts', ['id' => $book->id]))
            ->assertNotFound();
    }

    /**
     * A book with a small journal: two cover attempts (second accepted) and
     * one page attempt.
     *
     * @return array{0: User, 1: Book, 2: Page}
     */
    private function bookWithJournal(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        $book = Book::factory()->complete()->for($user)->for($template)->create();
        $page = Page::factory()->complete()->for($book)->create(['page_number' => 1]);

        ImagePrompt::query()->create([
            'book_id' => $book->id,
            'purpose' => 'cover',
            'attempt' => 1,
            'variant' => 'original',
            'prompt' => 'the original cover prompt',
            'accepted' => false,
        ]);
        ImagePrompt::query()->create([
            'book_id' => $book->id,
            'purpose' => 'cover',
            'attempt' => 2,
            'variant' => 'scrubbed',
            'prompt' => 'the scrubbed cover prompt',
            'accepted' => true,
        ]);
        ImagePrompt::query()->create([
            'book_id' => $book->id,
            'page_id' => $page->id,
            'purpose' => 'page',
            'attempt' => 1,
            'variant' => 'original',
            'prompt' => 'the page prompt',
            'accepted' => true,
        ]);

        return [$user, $book, $page];
    }
}
