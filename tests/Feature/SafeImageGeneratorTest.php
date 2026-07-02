<?php

namespace Tests\Feature;

use App\Services\AI\ImageReference;
use App\Services\AI\SafeImageGenerator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SafeImageGeneratorTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        Http::preventStrayRequests();
    }

    public function test_two_content_rejections_then_success_takes_three_attempts(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->chatResponse('A rewritten storybook prompt')),
            'api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['error' => ['message' => 'content policy violation']], 400)
                ->push(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1536x1024', [], 'test');

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);
        // The result carries the prompt that finally succeeded, not the original.
        $this->assertSame('A rewritten storybook prompt', $image->prompt);
        $this->assertSame(3, $image->attempt);

        $imagePrompts = $this->sentImagePrompts();
        $this->assertCount(3, $imagePrompts);
        $this->assertSame('a child in the forest', $imagePrompts[0]);
        $this->assertSame('a young character in the forest', $imagePrompts[1]);
        $this->assertSame('A rewritten storybook prompt', $imagePrompts[2]);

        Http::assertSentCount(4);
    }

    public function test_api_key_errors_fail_fast_without_retries(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'bad credentials']], 401),
        ]);

        $thrown = null;

        try {
            app(SafeImageGenerator::class)->generate('a child in the forest', '1536x1024', [], 'test');
        } catch (RequestException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(401, $thrown->response->status());

        Http::assertSentCount(1);
    }

    public function test_fourth_attempt_drops_the_reference_photos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('characters/1/photo-abc12345.png', base64_decode(self::PNG_BASE64));
        $reference = new ImageReference('characters/1/photo-abc12345.png', 'Mia');

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['error' => ['message' => 'content policy violation']], 400),
            'api.openai.com/v1/chat/completions' => Http::response($this->chatResponse('A rewritten storybook prompt')),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $image = app(SafeImageGenerator::class)->generate('a child in the forest', '1536x1024', [$reference], 'test');

        $this->assertSame(base64_decode(self::PNG_BASE64), $image->bytes);
        $this->assertSame(4, $image->attempt);

        $sentUrls = Http::recorded()
            ->map(fn (array $pair): string => $pair[0]->url())
            ->all();

        $this->assertSame(3, count(array_filter($sentUrls, fn (string $url): bool => str_contains($url, '/images/edits'))));
        $this->assertSame(1, count(array_filter($sentUrls, fn (string $url): bool => str_contains($url, '/images/generations'))));
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
}
