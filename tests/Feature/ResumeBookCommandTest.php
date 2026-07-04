<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResumeBookCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stuck_book_is_requeued_and_its_stale_job_removed(): void
    {
        Queue::fake();

        $book = $this->bookWithStatus(BookStatus::Generating);

        // The footprint a dead worker leaves: a reserved job row nobody owns.
        DB::table('jobs')->insert([
            'queue' => 'books',
            'payload' => '{"displayName":"App\\\\Jobs\\\\GenerateStorybookJob","data":{"command":"O:30:\\"App\\\\Jobs\\\\GenerateStorybookJob\\":1:{s:6:\\"bookId\\";i:'.$book->id.';}"}}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->artisan('cubfable:resume-book', ['book' => $book->id])
            ->assertSuccessful();

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_failed_books_can_be_resumed_too(): void
    {
        Queue::fake();

        $book = $this->bookWithStatus(BookStatus::Failed);

        $this->artisan('cubfable:resume-book', ['book' => $book->id])
            ->assertSuccessful();

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_complete_and_draft_books_are_refused(): void
    {
        Queue::fake();

        $complete = $this->bookWithStatus(BookStatus::Complete);
        $draft = $this->bookWithStatus(BookStatus::Draft);

        $this->artisan('cubfable:resume-book', ['book' => $complete->id])->assertFailed();
        $this->artisan('cubfable:resume-book', ['book' => $draft->id])->assertFailed();

        Queue::assertNothingPushed();
    }

    private function bookWithStatus(BookStatus $status): Book
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();

        return Book::factory()->for($user)->for($template)->create(['status' => $status]);
    }
}
