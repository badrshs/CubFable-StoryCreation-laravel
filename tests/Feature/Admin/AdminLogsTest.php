<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminLogsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A uniquely-named log file in the real storage/logs directory (the
     * controller reads the actual directory), removed after each test.
     */
    private string $logFile = 'laravel-1999-01-01.log';

    protected function setUp(): void
    {
        parent::setUp();

        File::put(storage_path('logs/'.$this->logFile), implode("\n", [
            '[1999-01-01 10:00:00] local.INFO: Book 5 generated cleanly.',
            '[1999-01-01 10:05:00] local.ERROR: Storybook generation failed: provider exploded.',
            '#0 /app/Services/StoryGenerator.php(100): boom()',
            '#1 {main}',
            '[1999-01-01 10:10:00] local.WARNING: [ai] page 1: attempt 1/4 failed.',
        ])."\n");
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('logs/'.$this->logFile));

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_per_book_logs_are_listed_and_readable(): void
    {
        File::ensureDirectoryExists(storage_path('logs/books'));
        File::put(storage_path('logs/books/book-190001.log'), implode("\n", [
            '[1999-01-01 10:00:00] book.INFO: Generation run started. {"pipeline":"classic"}',
            '[1999-01-01 10:01:00] book.ERROR: Storybook generation failed: provider exploded.',
        ])."\n");

        try {
            $this->withoutVite()
                ->actingAs($this->admin())
                ->get('/admin/logs?file=books/book-190001.log')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('admin/logs')
                    ->where('selected', 'books/book-190001.log')
                    ->has('entries', 2)
                    ->where('entries.0.level', 'error')
                    ->where('entries.1.level', 'info')
                    ->where('entries.1.message', 'Generation run started. {"pipeline":"classic"}'));
        } finally {
            File::delete(storage_path('logs/books/book-190001.log'));
        }
    }

    public function test_the_viewer_parses_entries_newest_first_with_level_counts(): void
    {
        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/logs?file='.$this->logFile)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/logs')
                ->where('selected', $this->logFile)
                ->has('entries', 3)
                ->where('entries.0.level', 'warning')
                ->where('entries.1.level', 'error')
                ->where('entries.1.message', 'Storybook generation failed: provider exploded.')
                ->where('counts.info', 1)
                ->where('counts.error', 1));
    }

    public function test_the_error_entry_carries_its_stack_trace_as_context(): void
    {
        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/logs?file='.$this->logFile.'&level=error')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.level', 'error')
                ->where('entries.0.context', "#0 /app/Services/StoryGenerator.php(100): boom()\n#1 {main}"));
    }

    public function test_search_filters_entries(): void
    {
        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/logs?file='.$this->logFile.'&search=generated cleanly')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.level', 'info'));
    }

    public function test_a_traversal_filename_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/logs')
            ->get('/admin/logs?file=../../.env')
            ->assertSessionHasErrors('file');

        $this->actingAs($this->admin())
            ->from('/admin/logs')
            ->get('/admin/logs/download?file=..%2F..%2F.env')
            ->assertSessionHasErrors('file');
    }

    public function test_clear_deletes_the_file(): void
    {
        $this->actingAs($this->admin())
            ->delete('/admin/logs?file='.$this->logFile)
            ->assertRedirect(route('admin.logs'));

        $this->assertFalse(File::exists(storage_path('logs/'.$this->logFile)));
    }

    public function test_clear_all_deletes_every_log(): void
    {
        File::ensureDirectoryExists(storage_path('logs/books'));
        $bookLog = storage_path('logs/books/book-190001.log');
        File::put($bookLog, "[1999-01-01 10:00:00] book.INFO: Generation run started.\n");

        try {
            $this->actingAs($this->admin())
                ->delete('/admin/logs/all')
                ->assertRedirect(route('admin.logs'));

            $this->assertFalse(File::exists(storage_path('logs/'.$this->logFile)));
            $this->assertFalse(File::exists($bookLog));
        } finally {
            File::delete($bookLog);
        }
    }

    public function test_download_streams_the_file(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/logs/download?file='.$this->logFile)
            ->assertOk()
            ->assertDownload($this->logFile);
    }

    public function test_non_admins_get_404(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/logs')->assertNotFound();
        $this->actingAs($user)->delete('/admin/logs?file='.$this->logFile)->assertNotFound();
        $this->actingAs($user)->delete('/admin/logs/all')->assertNotFound();
    }
}
