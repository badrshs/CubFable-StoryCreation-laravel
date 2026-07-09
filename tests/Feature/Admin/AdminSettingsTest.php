<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * A full valid payload built from the current effective values.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $base = [];

        foreach (app(AppSettings::class)->all() as $key => $entry) {
            $base[$key] = $entry['value'];
        }

        return [...$base, ...$overrides];
    }

    public function test_the_settings_page_lists_every_registered_setting(): void
    {
        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settings')
                ->has('settings.text_provider')
                ->has('settings.pages_min')
                ->where('settings.text_provider.overridden', false));
    }

    public function test_saving_overrides_the_env_backed_config(): void
    {
        config()->set('cubfable.ai.text_provider', 'openai');

        $this->actingAs($this->admin())
            ->put('/admin/settings', $this->payload([
                'text_provider' => 'openrouter',
                'text_model_openrouter' => 'deepseek/deepseek-v4-pro',
                'pages_min' => 5,
                'pages_max' => 8,
            ]))
            ->assertRedirect();

        // set() applies immediately within this process...
        $this->assertSame('openrouter', config('cubfable.ai.text_provider'));
        $this->assertSame(5, config('cubfable.pages_min'));

        // ...and a fresh apply() (what a new boot does) restores the same values.
        config()->set('cubfable.ai.text_provider', 'openai');
        app(AppSettings::class)->apply();
        $this->assertSame('openrouter', config('cubfable.ai.text_provider'));
    }

    public function test_unregistered_keys_are_ignored_and_null_clears_an_override(): void
    {
        $settings = app(AppSettings::class);

        $settings->set(['text_provider' => 'gemini', 'hacked_key' => 'x']);
        $this->assertDatabaseHas('settings', ['key' => 'text_provider']);
        $this->assertDatabaseMissing('settings', ['key' => 'hacked_key']);

        $settings->set(['text_provider' => null]);
        $this->assertDatabaseMissing('settings', ['key' => 'text_provider']);
    }

    public function test_page_bounds_must_be_coherent(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings', $this->payload(['pages_min' => 9, 'pages_max' => 5]))
            ->assertSessionHasErrors(['pages_min', 'pages_max']);

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_pdf_page_size_saves_and_rejects_unknown_presets(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/settings', $this->payload(['pdf_page_size' => 'a4-portrait']))
            ->assertRedirect();

        $this->assertSame('a4-portrait', config('cubfable.pdf.page_size'));

        $this->actingAs($this->admin())
            ->put('/admin/settings', $this->payload(['pdf_page_size' => 'no-such-preset']))
            ->assertSessionHasErrors('pdf_page_size');
    }

    public function test_the_pdf_preview_streams_a_book_at_the_chosen_size_without_saving(): void
    {
        Storage::fake('public');

        $book = Book::factory()->complete()->create();
        Page::factory()->for($book)->create(['page_number' => 1, 'image_path' => null]);

        $response = $this->actingAs($this->admin())
            ->get("/admin/settings/pdf-preview?bookId={$book->id}&size=a4-portrait&variant=home");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // 210 x 297 mm in points: the preview honors the requested size.
        $this->assertStringContainsString('841.8897', $pdf);

        // Nothing was persisted: the preview never touches the setting.
        $this->assertDatabaseMissing('settings', ['key' => 'pdf_page_size']);

        $this->actingAs($this->admin())
            ->get("/admin/settings/pdf-preview?bookId={$book->id}&size=bogus&variant=home")
            ->assertSessionHasErrors('size');
    }

    public function test_non_admins_cannot_reach_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/settings')->assertNotFound();
        $this->actingAs($user)->put('/admin/settings', [])->assertNotFound();
        $this->actingAs($user)->get('/admin/settings/pdf-preview?bookId=1&size=a4-portrait&variant=home')->assertNotFound();
    }
}
