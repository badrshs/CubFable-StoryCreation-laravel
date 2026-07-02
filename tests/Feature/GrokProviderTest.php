<?php

namespace Tests\Feature;

use App\Exceptions\ImageContentRejectedException;
use App\Models\AiUsage;
use App\Services\AI\AiManager;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GrokProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'grok');
        config()->set('cubfable.ai.models.image.grok', 'grok-imagine-image');
        config()->set('cubfable.ai.grok_base_url', 'https://api.x.ai/v1');
        config()->set('cubfable.ai.keys.grok', 'test-key');

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_text_to_image_uses_the_generations_endpoint(): void
    {
        Http::fake([
            'api.x.ai/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['model'] === 'grok-imagine-image'
                && $data['response_format'] === 'b64_json'
                && ! array_key_exists('image', $data)
                && $request->header('Authorization')[0] === 'Bearer test-key';
        });
    }

    public function test_references_go_to_the_edits_endpoint_capped_at_three(): void
    {
        $references = [];

        for ($i = 1; $i <= 4; $i++) {
            Storage::disk('public')->put("characters/{$i}/photo-ref{$i}.png", (string) base64_decode(self::PNG_BASE64, true));
            $references[] = new ImageReference("characters/{$i}/photo-ref{$i}.png", "Ref {$i}");
        }

        Http::fake([
            'api.x.ai/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(AiManager::class)->generateImage('a hero on a hill', '1536x1024', $references);

        Http::assertSent(function (Request $request): bool {
            $image = $request->data()['image'];

            return str_ends_with($request->url(), '/images/edits')
                && is_array($image)
                && count($image) === 3
                && $image[0]['type'] === 'image_url'
                && str_starts_with($image[0]['url'], 'data:image/png;base64,');
        });
    }

    public function test_a_single_reference_is_sent_as_one_object(): void
    {
        Storage::disk('public')->put('characters/1/photo-solo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.x.ai/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(AiManager::class)->generateImage('a hero', '1536x1024', [new ImageReference('characters/1/photo-solo.png', 'Hero')]);

        Http::assertSent(function (Request $request): bool {
            $image = $request->data()['image'];

            return ($image['type'] ?? null) === 'image_url'
                && str_starts_with($image['url'], 'data:image/png;base64,');
        });
    }

    public function test_url_responses_are_downloaded_when_no_b64_is_returned(): void
    {
        Http::fake([
            'api.x.ai/v1/images/generations' => Http::response(['data' => [['url' => 'https://imgen.x.ai/output/abc.jpg']]]),
            'imgen.x.ai/*' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a fox', '1536x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);
    }

    public function test_content_policy_rejections_raise_the_rejection_exception(): void
    {
        Http::fake([
            'api.x.ai/*' => Http::response([
                'error' => ['message' => 'The request violates the content policy', 'code' => 'content_policy_violation'],
            ], 400),
        ]);

        $this->expectException(ImageContentRejectedException::class);

        app(AiManager::class)->generateImage('something blocked', '1536x1024');
    }

    public function test_usage_records_the_flat_per_image_estimate(): void
    {
        Http::fake([
            'api.x.ai/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(AiManager::class)->generateImage('a fox', '1536x1024');
        app(UsageCollector::class)->flush(null);

        $row = AiUsage::query()->latest('id')->first();
        $this->assertSame('grok', $row->provider);
        $this->assertSame(0.02, $row->cost_usd);
        $this->assertTrue($row->estimated);
    }

    public function test_a_missing_api_key_fails_fast(): void
    {
        config()->set('cubfable.ai.keys.grok', '');

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('XAI_API_KEY is not set.');

        app(AiManager::class)->generateImage('a fox', '1536x1024');
    }
}
