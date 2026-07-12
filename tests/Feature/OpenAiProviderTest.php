<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\AI\ImageReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.models.image.openai', 'gpt-image-1');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_every_reference_travels_to_the_edits_endpoint(): void
    {
        $references = [];

        for ($i = 1; $i <= 3; $i++) {
            Storage::disk('public')->put("characters/{$i}/photo-ref{$i}.png", (string) base64_decode(self::PNG_BASE64, true));
            $references[] = new ImageReference("characters/{$i}/photo-ref{$i}.png", "Ref {$i}");
        }

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(AiManager::class)->generateImage('a hero on a hill', '1024x1536', $references);

        Http::assertSent(function (Request $request): bool {
            $images = array_values(array_filter(
                $request->data(),
                fn (mixed $part): bool => is_array($part) && ($part['name'] ?? null) === 'image[]',
            ));

            return str_ends_with($request->url(), '/images/edits')
                && count($images) === 3
                && count(array_unique(array_column($images, 'filename'))) === 3;
        });
    }

    public function test_a_single_reference_keeps_the_plain_image_field(): void
    {
        Storage::disk('public')->put('characters/1/photo-solo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(AiManager::class)->generateImage('a hero', '1024x1536', [new ImageReference('characters/1/photo-solo.png', 'Hero')]);

        Http::assertSent(function (Request $request): bool {
            $images = array_values(array_filter(
                $request->data(),
                fn (mixed $part): bool => is_array($part) && ($part['name'] ?? null) === 'image',
            ));

            return str_ends_with($request->url(), '/images/edits') && count($images) === 1;
        });
    }
}
