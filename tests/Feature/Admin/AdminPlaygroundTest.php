<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_preview_composes_prompts_for_an_existing_book_without_any_ai_call(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'page_count' => 3,
            'description' => 'A pearl goes missing from the reef and only the bravest diver can find it.',
        ]);
        $book = Book::factory()->complete()->for($user)->for($template)->create(['child_name' => 'Mia']);

        $character = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'appearance' => 'Curly brown hair and a yellow raincoat.',
        ]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $response = $this->actingAs($this->admin())
            ->postJson('/admin/playground/preview', ['bookId' => $book->id])
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Mia', $response['prompts']['blueprint']);
        $this->assertStringContainsString('A pearl goes missing from the reef', $response['prompts']['blueprint']);
        $this->assertStringContainsString('Front cover', $response['prompts']['cover']);
        $this->assertStringContainsString('page 1', $response['prompts']['page']);

        Http::assertNothingSent();
    }

    public function test_preview_composes_prompts_from_sample_inputs(): void
    {
        $template = Template::factory()->create(['theme' => 'forest']);

        $response = $this->actingAs($this->admin())
            ->postJson('/admin/playground/preview', [
                'templateId' => $template->id,
                'childName' => 'Nour',
                'ageRange' => '6-8',
                'artStyle' => 'watercolor',
                'language' => 'ar',
            ])
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Nour', $response['prompts']['blueprint']);
        $this->assertStringContainsString('Arabic', $response['prompts']['blueprint']);
        $this->assertStringContainsString('watercolor', $response['prompts']['cover']);

        Http::assertNothingSent();
    }

    public function test_run_text_executes_one_real_text_call(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Once upon a time...']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/admin/playground/run-text', ['prompt' => 'Write a one line story.'])
            ->assertOk()
            ->assertJson(['content' => 'Once upon a time...']);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage', ['kind' => 'text', 'book_id' => null]);
    }

    public function test_run_image_executes_one_real_image_call(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson('/admin/playground/run-image', ['prompt' => 'A cozy fox.', 'size' => '1024x1536'])
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('data:image/png;base64,', $response['dataUrl']);
    }

    public function test_run_image_honors_a_replicate_engine_override(): void
    {
        config()->set('cubfable.ai.keys.replicate', 'test-token');
        config()->set('cubfable.ai.replicate_base_url', 'https://api.replicate.com');

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-pro/predictions' => Http::response([
                'id' => 'pred-pg',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/pg.png'],
            ]),
            'replicate.delivery/pg.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson('/admin/playground/run-image', [
                'prompt' => 'A cozy fox.',
                'provider' => 'replicate',
                'model' => 'bytedance/seedream-5-pro',
            ])
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('data:image/png;base64,', $response['dataUrl']);
        $this->assertDatabaseHas('ai_usage', ['provider' => 'replicate', 'model' => 'bytedance/seedream-5-pro', 'book_id' => null]);
    }

    public function test_the_playground_exposes_the_replicate_engine_catalog(): void
    {
        // The dev-mode SSR gateway is an HTTP call; stub it so
        // preventStrayRequests does not trip on rendering the page.
        Http::fake(['*__inertia_ssr*' => Http::response(['head' => [], 'body' => ''])]);

        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/playground')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/playground')
                ->where('replicateEngines.0.model', 'bytedance/seedream-5-pro')
                ->has('replicateEngines.0.cost'));
    }

    public function test_non_admins_get_404(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/playground')->assertNotFound();
        $this->actingAs($user)->postJson('/admin/playground/run-text', ['prompt' => 'x'])->assertNotFound();
    }
}
