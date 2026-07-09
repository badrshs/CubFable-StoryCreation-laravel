<?php

namespace Tests\Feature;

use App\Services\AI\ImageReference;
use App\Services\AI\Providers\OpenRouterProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenRouterImageReferencesTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'openrouter');
        config()->set('cubfable.ai.keys.openrouter', 'test-key');
        config()->set('cubfable.ai.models.image.openrouter', 'x-ai/grok-imagine-image-quality');
        // Independent of whatever IMAGE_MAX_REFERENCES the local .env sets.
        config()->set('cubfable.ai.max_image_references', 0);

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_every_reference_is_forwarded_as_an_image_url_part(): void
    {
        $references = $this->makeReferences(2);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->imageResponse()),
        ]);

        app(OpenRouterProvider::class)->image('a hero on a hill', '1536x1024', $references);

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['messages'][0]['content'];
            $imageParts = array_filter($content, fn (array $part): bool => $part['type'] === 'image_url');

            return $request->data()['model'] === 'x-ai/grok-imagine-image-quality'
                && count($imageParts) === 2
                && str_starts_with($content[1]['image_url']['url'], 'data:image/png;base64,');
        });
    }

    public function test_vision_uses_the_dedicated_model_when_the_text_model_is_text_only(): void
    {
        config()->set('cubfable.ai.models.text.openrouter', 'deepseek/deepseek-v4-pro');
        config()->set('cubfable.ai.models.vision.openrouter', 'google/gemini-2.5-flash');

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'a cheerful child']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $description = app(OpenRouterProvider::class)->describe('Describe the child.', 'data:image/png;base64,'.self::PNG_BASE64);

        $this->assertSame('a cheerful child', $description);
        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'google/gemini-2.5-flash');
    }

    public function test_vision_falls_back_to_the_text_model_when_no_vision_model_is_set(): void
    {
        config()->set('cubfable.ai.models.text.openrouter', 'google/gemini-2.5-flash');
        config()->set('cubfable.ai.models.vision.openrouter', '');

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'a cheerful child']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        app(OpenRouterProvider::class)->describe('Describe the child.', 'data:image/png;base64,'.self::PNG_BASE64);

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'google/gemini-2.5-flash');
    }

    public function test_references_are_capped_keeping_the_most_important_first(): void
    {
        config()->set('cubfable.ai.max_image_references', 3);

        $references = $this->makeReferences(5);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->imageResponse()),
        ]);

        app(OpenRouterProvider::class)->image('a hero on a hill', '1536x1024', $references);

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['messages'][0]['content'];
            $imageParts = array_values(array_filter($content, fn (array $part): bool => $part['type'] === 'image_url'));

            // The first three of the five references survive; order preserved.
            return count($imageParts) === 3;
        });
    }

    public function test_image_only_models_get_an_automatic_modalities_fallback(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push(['error' => ['message' => 'No endpoints found that support the requested output modalities: image, text', 'code' => 404]], 404)
                ->push($this->imageResponse()),
        ]);

        app(OpenRouterProvider::class)->image('a hero on a hill', '1536x1024', []);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame(['image', 'text'], $recorded->first()[0]->data()['modalities']);
        $this->assertSame(['image'], $recorded->last()[0]->data()['modalities']);
    }

    public function test_no_cap_by_default(): void
    {
        $references = $this->makeReferences(5);

        Http::fake([
            'openrouter.ai/*' => Http::response($this->imageResponse()),
        ]);

        app(OpenRouterProvider::class)->image('a hero on a hill', '1536x1024', $references);

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['messages'][0]['content'];

            return count(array_filter($content, fn (array $part): bool => $part['type'] === 'image_url')) === 5;
        });
    }

    /**
     * @return list<ImageReference>
     */
    private function makeReferences(int $count): array
    {
        $references = [];

        for ($i = 1; $i <= $count; $i++) {
            $path = "characters/{$i}/photo-ref{$i}.png";
            Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));
            $references[] = new ImageReference($path, "Ref {$i}");
        }

        return $references;
    }

    /**
     * An OpenRouter image response in the message.images shape.
     *
     * @return array<string, mixed>
     */
    private function imageResponse(): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => '',
                    'images' => [['image_url' => ['url' => 'data:image/png;base64,'.self::PNG_BASE64]]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 1290, 'total_tokens' => 1390, 'cost' => 0.05],
        ];
    }
}
