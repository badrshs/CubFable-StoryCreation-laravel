<?php

namespace Tests\Feature;

use App\Exceptions\ImageFlaggedSensitiveException;
use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\Template;
use App\Models\User;
use App\Services\AI\PromptLogContext;
use App\Services\AI\SafeImageGenerator;
use App\Services\BookStopSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SafeImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.models.image.openai', 'gpt-image-1');
        config()->set('cubfable.ai.keys.openai', 'test-key');
        config()->set('cubfable.ai.fallback_engines', '');

        Http::preventStrayRequests();
    }

    public function test_content_flags_walk_the_engine_chain_without_touching_the_prompt(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model,openai:third-model');

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);
        // The fallback engine received the ORIGINAL prompt, untouched.
        $this->assertSame('a child in the forest', $image->prompt);

        $this->assertSame(['gpt-image-1', 'backup-model'], $this->sentImageModels());
        $this->assertSame(['a child in the forest', 'a child in the forest'], $this->sentImagePrompts());

        // No prompt rewrite happened: not a single text call.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_the_prompt_is_rewritten_only_after_the_whole_chain_refuses(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model');

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->chatResponse('A rewritten storybook prompt')),
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['error' => ['message' => 'flagged as sensitive']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');

        $this->assertSame('A rewritten storybook prompt', $image->prompt);
        $this->assertSame(3, $image->attempt);

        // Round 1: original prompt on both engines. Round 2: rewritten prompt
        // starting over on the primary engine.
        $this->assertSame(['gpt-image-1', 'backup-model', 'gpt-image-1'], $this->sentImageModels());
        $this->assertSame(
            ['a child in the forest', 'a child in the forest', 'A rewritten storybook prompt'],
            $this->sentImagePrompts(),
        );

        $this->assertSame(1, Http::recorded(fn ($request): bool => str_contains($request->url(), 'chat/completions'))->count());
    }

    public function test_exhausting_both_rounds_flags_the_image_as_sensitive(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model');

        $user = User::factory()->create();
        $template = Template::factory()->create();
        $book = Book::factory()->pending()->for($user)->for($template)->create();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->chatResponse('A rewritten storybook prompt')),
            'api.openai.com/v1/images/generations' => Http::response(['error' => ['message' => 'content policy violation']], 400),
        ]);

        $thrown = null;

        try {
            app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test', new PromptLogContext($book->id, 'page'));
        } catch (ImageFlaggedSensitiveException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);

        // The whole flow is journaled: engine, round, variant and error per attempt.
        $journal = ImagePrompt::query()->where('book_id', $book->id)->orderBy('id')->get();
        $this->assertCount(4, $journal);
        $this->assertSame([1, 1, 2, 2], $journal->pluck('round')->all());
        $this->assertSame(['original', 'original', 'safe-rewrite', 'safe-rewrite'], $journal->pluck('variant')->all());
        $this->assertSame(['gpt-image-1', 'backup-model', 'gpt-image-1', 'backup-model'], $journal->pluck('model')->all());
        $this->assertSame(['openai', 'openai', 'openai', 'openai'], $journal->pluck('provider')->all());
        $this->assertTrue($journal->every(fn (ImagePrompt $row): bool => ! $row->accepted));
        $this->assertTrue($journal->every(fn (ImagePrompt $row): bool => str_contains((string) $row->error, 'content policy violation')));
    }

    public function test_an_admin_stop_aborts_before_the_next_attempt(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model');

        $user = User::factory()->create();
        $template = Template::factory()->create();
        $book = Book::factory()->pending()->for($user)->for($template)->create();

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(BookStopSignal::class)->request($book->id);

        $thrown = null;

        try {
            app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test', new PromptLogContext($book->id, 'page'));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        // Not a single engine attempt starts once the stop flag is set: the
        // stop lands within one attempt instead of one full fallback walk.
        $this->assertNotNull($thrown);
        $this->assertSame('Generation stopped by the admin.', $thrown->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_stop_for_another_book_does_not_interfere(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        $book = Book::factory()->pending()->for($user)->for($template)->create();
        $otherBook = Book::factory()->pending()->for($user)->for($template)->create();

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(BookStopSignal::class)->request($otherBook->id);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test', new PromptLogContext($book->id, 'page'));

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);
    }

    public function test_each_engine_composes_its_own_prompt(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model');

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(SafeImageGenerator::class)->generate(
            'unused seed prompt',
            '1024x1536',
            [],
            'test',
            null,
            fn (): array => ['prompt' => 'scene for '.config('cubfable.ai.models.image.openai'), 'references' => []],
        );

        // The compose callback ran once per engine, seeing that engine's config.
        $this->assertSame(['scene for gpt-image-1', 'scene for backup-model'], $this->sentImagePrompts());
    }

    public function test_transient_errors_retry_the_same_engine_and_never_rewrite(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'openai:backup-model');

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'upstream exploded']], 500)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);

        // Same engine, same prompt: infrastructure hiccups are not safety problems.
        $this->assertSame(['gpt-image-1', 'gpt-image-1'], $this->sentImageModels());
        $this->assertSame(['a child in the forest', 'a child in the forest'], $this->sentImagePrompts());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_persistent_transient_errors_fail_without_a_pointless_rewrite_round(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['error' => ['message' => 'upstream exploded']], 500),
        ]);

        $thrown = null;

        try {
            app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');
        } catch (RequestException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);

        // Two tries on the only engine, then out: no round 2, no text call.
        Http::assertSentCount(2);
    }

    public function test_a_dead_fallback_engine_is_skipped(): void
    {
        config()->set('cubfable.ai.fallback_engines', 'gemini:some-model,openai:backup-model');
        config()->set('cubfable.ai.keys.gemini', '');

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);
        // Gemini (no key) was skipped without killing the run; no Gemini HTTP call happened.
        $this->assertSame(['gpt-image-1', 'backup-model'], $this->sentImageModels());
    }

    public function test_every_attempt_is_journaled_with_the_accepted_one_flagged(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        $book = Book::factory()->pending()->for($user)->for($template)->create();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->chatResponse('A rewritten storybook prompt')),
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(SafeImageGenerator::class)->generate(
            'a child in the forest',
            '1024x1536',
            [],
            'test',
            new PromptLogContext($book->id, 'cover'),
        );

        $journal = ImagePrompt::query()->where('book_id', $book->id)->orderBy('id')->get();

        $this->assertCount(2, $journal);
        $this->assertSame(['original', 'safe-rewrite'], $journal->pluck('variant')->all());
        $this->assertSame([false, true], $journal->pluck('accepted')->all());
        $this->assertSame([1, 2], $journal->pluck('round')->all());
        // The untouched original prompt is always the first row.
        $this->assertSame('a child in the forest', $journal->first()->prompt);
        $this->assertSame('cover', $journal->first()->purpose);
        $this->assertNotNull($journal->first()->error);
        $this->assertNull($journal->last()->error);
    }

    public function test_api_key_errors_fail_fast_without_retries(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'bad credentials']], 401),
        ]);

        $thrown = null;

        try {
            app(SafeImageGenerator::class)->generate('a child in the forest', '1024x1536', [], 'test');
        } catch (RequestException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(401, $thrown->response->status());

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }

    /**
     * The prompts sent to the images/generations endpoint, in order.
     *
     * @return list<string>
     */
    private function sentImagePrompts(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/images/generations'))
            ->map(fn (array $pair): string => (string) ($pair[0]->data()['prompt'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * The models sent to the images/generations endpoint, in order.
     *
     * @return list<string>
     */
    private function sentImageModels(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/images/generations'))
            ->map(fn (array $pair): string => (string) ($pair[0]->data()['model'] ?? ''))
            ->values()
            ->all();
    }
}
