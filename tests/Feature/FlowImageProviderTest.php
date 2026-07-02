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
use Tests\TestCase;

class FlowImageProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'flow');
        config()->set('cubfable.ai.models.image.flow', 'grok-imagine');
        config()->set('cubfable.ai.flow_base_url', 'http://127.0.0.1:8787');
        config()->set('cubfable.ai.keys.flow', '');

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_generates_through_the_local_gateway(): void
    {
        Http::fake([
            '127.0.0.1:8787/*' => Http::response($this->gatewayResponse()),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/v1/images/generations')
                && $data['model'] === 'grok-imagine'
                && $data['response_format'] === 'b64_json'
                && ! array_key_exists('image', $data)
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_only_the_most_important_reference_is_attached(): void
    {
        Storage::disk('public')->put('books/1/sheet-abc.png', (string) base64_decode(self::PNG_BASE64, true));
        Storage::disk('public')->put('characters/1/photo-def.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            '127.0.0.1:8787/*' => Http::response($this->gatewayResponse()),
        ]);

        app(AiManager::class)->generateImage('a hero on a hill', '1536x1024', [
            new ImageReference('books/1/sheet-abc.png', 'sheet'),
            new ImageReference('characters/1/photo-def.png', 'photo'),
        ]);

        Http::assertSent(function (Request $request): bool {
            $image = $request->data()['image'] ?? null;

            return is_string($image) && str_starts_with($image, 'data:image/png;base64,');
        });
    }

    public function test_the_api_key_travels_as_a_bearer_header_when_configured(): void
    {
        config()->set('cubfable.ai.keys.flow', 'secret-key');

        Http::fake([
            '127.0.0.1:8787/*' => Http::response($this->gatewayResponse()),
        ]);

        app(AiManager::class)->generateImage('a watercolor fox', '1536x1024');

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer secret-key');
    }

    public function test_content_policy_rejections_raise_the_rejection_exception(): void
    {
        Http::fake([
            '127.0.0.1:8787/*' => Http::response([
                'error' => ['message' => 'The provider rejected the prompt', 'type' => 'invalid_request_error', 'code' => 'content_policy_violation'],
            ], 400),
        ]);

        $this->expectException(ImageContentRejectedException::class);

        app(AiManager::class)->generateImage('something blocked', '1536x1024');
    }

    public function test_usage_is_recorded_at_zero_cost(): void
    {
        Http::fake([
            '127.0.0.1:8787/*' => Http::response($this->gatewayResponse()),
        ]);

        app(AiManager::class)->generateImage('a watercolor fox', '1536x1024');
        app(UsageCollector::class)->flush(null);

        $row = AiUsage::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('flow', $row->provider);
        $this->assertSame('grok-imagine', $row->model);
        $this->assertSame(0.0, $row->cost_usd);
        $this->assertFalse($row->estimated);
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayResponse(): array
    {
        return [
            'created' => 1751450000,
            'data' => [[
                'id' => 'a1b2c3',
                'object' => 'image',
                'model' => 'grok-imagine',
                'provider' => 'grok',
                'b64_json' => self::PNG_BASE64,
            ]],
        ];
    }
}
