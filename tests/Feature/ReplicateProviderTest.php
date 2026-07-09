<?php

namespace Tests\Feature;

use App\Exceptions\ImageContentRejectedException;
use App\Services\AI\AiManager;
use App\Services\AI\ImageReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ReplicateProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.models.image.replicate', 'black-forest-labs/flux-kontext-pro');
        config()->set('cubfable.ai.replicate_base_url', 'https://api.replicate.com');
        config()->set('cubfable.ai.keys.replicate', 'test-token');

        // Pre-seed the model input schema so the provider adapts its payload
        // without a metadata fetch (Kontext: single input_image + aspect).
        $this->seedSchema('black-forest-labs/flux-kontext-pro', [
            'prompt' => ['type' => 'string'],
            'input_image' => ['type' => 'string', 'format' => 'uri'],
            'aspect_ratio' => ['type' => 'string'],
            'output_format' => ['type' => 'string'],
        ]);

        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function seedSchema(string $model, array $properties): void
    {
        Cache::put("replicate.input-schema.{$model}", $properties, now()->addHour());
    }

    public function test_a_reference_runs_kontext_via_the_files_api(): void
    {
        Storage::disk('public')->put('characters/1/photo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::response([
                'urls' => ['get' => 'https://api.replicate.com/v1/files/abc/download'],
            ]),
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-1',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/result.png',
            ]),
            'replicate.delivery/result.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage(
            'a watercolor fox at dawn',
            '1536x1024',
            [new ImageReference('characters/1/photo.png', 'Mia')],
        );

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return $input['input_image'] === 'https://api.replicate.com/v1/files/abc/download'
                && $input['aspect_ratio'] === '3:2'
                && $input['prompt'] === 'a watercolor fox at dawn'
                && $request->header('Prefer')[0] === 'wait'
                && $request->header('Authorization')[0] === 'Bearer test-token';
        });
    }

    public function test_seedream_style_models_get_an_image_array_and_exact_pixels(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');

        // Seedream's real schema shape: an image_input ARRAY plus
        // size/width/height; no aspect-only sizing, no output_format.
        $this->seedSchema('bytedance/seedream-4.5', [
            'prompt' => ['type' => 'string'],
            'image_input' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
            'size' => ['default' => '2K'],
            'width' => ['type' => 'integer'],
            'height' => ['type' => 'integer'],
            'aspect_ratio' => ['default' => 'match_input_image'],
        ]);

        Storage::disk('public')->put('characters/1/photo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::response([
                'urls' => ['get' => 'https://api.replicate.com/v1/files/ref1/download'],
            ]),
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::response([
                'id' => 'pred-sd',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/seedream.png',
            ]),
            'replicate.delivery/seedream.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage(
            'a watercolor fox at dawn',
            '1536x1024',
            [new ImageReference('characters/1/photo.png', 'Mia')],
        );

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            // The reference travels in the ARRAY field this model actually
            // reads, and sizing uses the model's own presets: custom pixel
            // dimensions trip Seedream's hidden ~1920x1920 minimum (E006).
            return $input['image_input'] === ['https://api.replicate.com/v1/files/ref1/download']
                && ! array_key_exists('input_image', $input)
                && $input['size'] === '4K'
                && $input['aspect_ratio'] === '3:2'
                && ! array_key_exists('width', $input)
                && ! array_key_exists('height', $input)
                && ! array_key_exists('output_format', $input);
        });
    }

    public function test_size_and_aspect_ratio_honor_the_model_enum(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-lite');

        // Seedream 5 lite only offers 2K/3K (no 4K) and a narrower set of
        // aspect ratios: the payload must stay inside both enums or the
        // model rejects it with a 422 validation error.
        $this->seedSchema('bytedance/seedream-5-lite', [
            'prompt' => ['type' => 'string'],
            'image_input' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
            'size' => ['type' => 'string', 'enum' => ['2K', '3K']],
            'aspect_ratio' => ['type' => 'string', 'enum' => ['match_input_image', '1:1', '4:3', '3:4', '16:9', '9:16', '3:2', '2:3', '21:9']],
        ]);

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-lite/predictions' => Http::response([
                'id' => 'pred-lite',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/lite.png',
            ]),
            'replicate.delivery/lite.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            // Highest tier the model actually allows (3K, not 4K) and an
            // aspect ratio present in the model's own list.
            return $input['size'] === '3K'
                && $input['aspect_ratio'] === '3:2';
        });
    }

    public function test_the_schema_fetch_resolves_referenced_enums(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-lite');
        Cache::forget('replicate.input-schema.bytedance/seedream-5-lite');

        // Replicate exposes enums as separate schema components referenced by
        // the property via allOf/$ref, so the fetch must resolve them.
        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-lite' => Http::response([
                'latest_version' => [
                    'openapi_schema' => [
                        'components' => [
                            'schemas' => [
                                'size' => ['type' => 'string', 'enum' => ['2K', '3K']],
                                'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '3:2', '2:3']],
                                'Input' => [
                                    'properties' => [
                                        'prompt' => ['type' => 'string'],
                                        'size' => ['allOf' => [['$ref' => '#/components/schemas/size']], 'default' => '2K'],
                                        'aspect_ratio' => ['allOf' => [['$ref' => '#/components/schemas/aspect_ratio']], 'default' => 'match_input_image'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            'api.replicate.com/v1/models/bytedance/seedream-5-lite/predictions' => Http::response([
                'id' => 'pred-lite-2',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/lite2.png',
            ]),
            'replicate.delivery/lite2.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1024x1536');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return $input['size'] === '3K'
                && $input['aspect_ratio'] === '2:3';
        });
    }

    public function test_nano_banana_sets_resolution_and_sends_every_reference(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'google/nano-banana-2');

        // Nano Banana 2's real shape: a multi-image "image_input" array and a
        // "resolution" tier (not "size") that defaults to 1K when unset.
        $this->seedSchema('google/nano-banana-2', [
            'prompt' => ['type' => 'string'],
            'image_input' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
            'resolution' => ['type' => 'string', 'enum' => ['1K', '2K', '4K']],
            'aspect_ratio' => ['type' => 'string', 'enum' => ['match_input_image', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3']],
            'output_format' => ['type' => 'string', 'enum' => ['jpg', 'png']],
        ]);

        Storage::disk('public')->put('characters/1/mia.png', (string) base64_decode(self::PNG_BASE64, true));
        Storage::disk('public')->put('characters/2/leo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::sequence()
                ->push(['urls' => ['get' => 'https://api.replicate.com/v1/files/mia/download']])
                ->push(['urls' => ['get' => 'https://api.replicate.com/v1/files/leo/download']]),
            'api.replicate.com/v1/models/google/nano-banana-2/predictions' => Http::response([
                'id' => 'pred-nb',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/nb.png',
            ]),
            'replicate.delivery/nb.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage(
            'a watercolor fox at dawn',
            '1536x1024',
            [
                new ImageReference('characters/1/mia.png', 'Mia'),
                new ImageReference('characters/2/leo.png', 'Leo'),
            ],
        );

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            // Every reference travels in the array, resolution rides the
            // model's own "resolution" field at its top tier, and the old
            // "size" field is never sent.
            return $input['image_input'] === [
                'https://api.replicate.com/v1/files/mia/download',
                'https://api.replicate.com/v1/files/leo/download',
            ]
                && $input['resolution'] === '4K'
                && ! array_key_exists('size', $input)
                && $input['aspect_ratio'] === '3:2';
        });
    }

    public function test_no_reference_generates_from_text_alone(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-2',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/result.png'],
            ]),
            'replicate.delivery/result.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a cozy owl', '1024x1536');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return ! array_key_exists('input_image', $input) && $input['aspect_ratio'] === '2:3';
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/files'));
    }

    public function test_a_throttled_request_waits_and_retries(): void
    {
        // Accounts under $5 credit are throttled to 1 request per burst: a
        // 429 must wait retry_after and retry, not fail the whole job.
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::sequence()
                ->push(['detail' => 'Request was throttled.', 'retry_after' => 3], 429)
                ->push([
                    'id' => 'pred-throttled',
                    'status' => 'succeeded',
                    'output' => 'https://replicate.delivery/result.png',
                ]),
            'replicate.delivery/result.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a cozy owl', '1024x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 4, 1);
    }

    public function test_a_slow_prediction_is_polled_to_completion(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions/pred-3' => Http::sequence()
                ->push(['id' => 'pred-3', 'status' => 'processing'])
                ->push(['id' => 'pred-3', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/slow.png']),
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-3',
                'status' => 'processing',
                'urls' => ['get' => 'https://api.replicate.com/v1/predictions/pred-3'],
            ]),
            'replicate.delivery/slow.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a cozy owl', '1024x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);
    }

    public function test_failures_throw_and_safety_refusals_feed_the_ladder(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-4',
                'status' => 'failed',
                'error' => 'GPU exploded',
            ]),
        ]);

        try {
            app(AiManager::class)->generateImage('a cozy owl', '1024x1024');
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('GPU exploded', $exception->getMessage());
        }
    }

    public function test_a_safety_refusal_feeds_the_rephrase_ladder(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-5',
                'status' => 'failed',
                'error' => 'content flagged as sensitive',
            ]),
        ]);

        $this->expectException(ImageContentRejectedException::class);

        app(AiManager::class)->generateImage('a cozy owl', '1024x1024');
    }
}
