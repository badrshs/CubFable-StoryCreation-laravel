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
        config()->set('cubfable.ai.image_quality', 'high');

        // Cataloged models build their payload from hand-verified
        // capabilities, so no schema is seeded and no metadata fetch may
        // happen (preventStrayRequests would fail the test if one did).
        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
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
                && $input['output_format'] === 'png'
                && $input['prompt'] === 'a watercolor fox at dawn'
                && $request->header('Prefer')[0] === 'wait'
                && $request->header('Authorization')[0] === 'Bearer test-token';
        });
    }

    public function test_seedream_gets_an_image_array_and_its_own_size_tiers(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');

        Storage::disk('public')->put('characters/1/photo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::response([
                'urls' => ['get' => 'https://api.replicate.com/v1/files/ref1/download'],
            ]),
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::response([
                'id' => 'pred-sd',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/seedream.png'],
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
            // reads; the quality preference (high) picks its 2K tier; and
            // Seedream 4.5 has NO output_format field, so none is sent.
            return $input['image_input'] === ['https://api.replicate.com/v1/files/ref1/download']
                && ! array_key_exists('input_image', $input)
                && $input['size'] === '2K'
                && $input['aspect_ratio'] === '3:2'
                && ! array_key_exists('width', $input)
                && ! array_key_exists('height', $input)
                && ! array_key_exists('output_format', $input);
        });
    }

    public function test_image_quality_max_picks_the_largest_tier(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');
        config()->set('cubfable.ai.image_quality', 'max');

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::response([
                'id' => 'pred-max',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/max.png'],
            ]),
            'replicate.delivery/max.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/predictions')
                && ((array) $request->data()['input'])['size'] === '4K';
        });
    }

    public function test_image_quality_standard_picks_the_smallest_tier(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-pro');
        config()->set('cubfable.ai.image_quality', 'standard');

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-pro/predictions' => Http::response([
                'id' => 'pred-std',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/std.png'],
            ]),
            'replicate.delivery/std.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return $input['size'] === '1K' && $input['output_format'] === 'png';
        });
    }

    public function test_seedream_5_pro_extracts_the_array_output_and_records_tiered_cost(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-pro');

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-pro/predictions' => Http::response([
                'id' => 'pred-5pro',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/5pro.png'],
            ]),
            'replicate.delivery/5pro.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1536x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        // Quality "high" runs Seedream 5 Pro at 2K, which costs $0.09/image
        // (its 1K tier is $0.045) - the flat $0.04 estimate would be wrong.
        app(UsageCollector::class)->flush(null);

        $usage = AiUsage::query()->where('provider', 'replicate')->sole();
        $this->assertSame('bytedance/seedream-5-pro', $usage->model);
        $this->assertEqualsWithDelta(0.09, (float) $usage->cost_usd, 0.0001);
    }

    public function test_seedream_5_lite_honors_its_own_size_enum(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-lite');
        config()->set('cubfable.ai.image_quality', 'max');

        Http::fake([
            'api.replicate.com/v1/models/bytedance/seedream-5-lite/predictions' => Http::response([
                'id' => 'pred-lite',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/lite.png'],
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

            // The largest tier this model actually offers (3K, never 4K),
            // and an aspect ratio present in its own list.
            return $input['size'] === '3K'
                && $input['aspect_ratio'] === '3:2'
                && $input['output_format'] === 'png';
        });
    }

    public function test_nano_banana_sets_resolution_and_sends_every_reference(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'google/nano-banana-2');

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

            // Every reference travels in the array; the tier rides this
            // model's own "resolution" field (never "size"), explicitly -
            // Nano Banana 2 defaults to a washed-out 1K when unset - and
            // png is forced over its jpg default.
            return $input['image_input'] === [
                'https://api.replicate.com/v1/files/mia/download',
                'https://api.replicate.com/v1/files/leo/download',
            ]
                && $input['resolution'] === '2K'
                && ! array_key_exists('size', $input)
                && $input['aspect_ratio'] === '3:2'
                && $input['output_format'] === 'png';
        });
    }

    public function test_flux_pro_takes_a_single_image_prompt_reference(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'black-forest-labs/flux-1.1-pro');

        Storage::disk('public')->put('characters/1/photo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::response([
                'urls' => ['get' => 'https://api.replicate.com/v1/files/flux/download'],
            ]),
            'api.replicate.com/v1/models/black-forest-labs/flux-1.1-pro/predictions' => Http::response([
                'id' => 'pred-flux',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/flux.png',
            ]),
            'replicate.delivery/flux.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
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

            // Flux 1.1 Pro's reference field is a SINGLE image_prompt uri
            // (composition guidance), width/height stay out (they only
            // apply with aspect_ratio=custom), and png replaces its webp
            // default.
            return $input['image_prompt'] === 'https://api.replicate.com/v1/files/flux/download'
                && ! array_key_exists('input_image', $input)
                && ! array_key_exists('width', $input)
                && ! array_key_exists('height', $input)
                && $input['aspect_ratio'] === '3:2'
                && $input['output_format'] === 'png';
        });
    }

    public function test_flux_2_sends_a_reference_array_and_megapixel_tiers(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'black-forest-labs/flux-2-pro');

        Storage::disk('public')->put('characters/1/mia.png', (string) base64_decode(self::PNG_BASE64, true));
        Storage::disk('public')->put('characters/2/leo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.replicate.com/v1/files' => Http::sequence()
                ->push(['urls' => ['get' => 'https://api.replicate.com/v1/files/mia/download']])
                ->push(['urls' => ['get' => 'https://api.replicate.com/v1/files/leo/download']]),
            'api.replicate.com/v1/models/black-forest-labs/flux-2-pro/predictions' => Http::response([
                'id' => 'pred-f2',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/f2.png',
            ]),
            'replicate.delivery/f2.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
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

            // FLUX.2's reference field is the PLURAL input_images array (not
            // Kontext's input_image); the tier rides its megapixel-named
            // "resolution" enum ("2 MP" at quality high, never left to the
            // 1 MP default), and png replaces the webp default.
            return $input['input_images'] === [
                'https://api.replicate.com/v1/files/mia/download',
                'https://api.replicate.com/v1/files/leo/download',
            ]
                && ! array_key_exists('input_image', $input)
                && $input['resolution'] === '2 MP'
                && $input['aspect_ratio'] === '3:2'
                && $input['output_format'] === 'png';
        });

        // Flux 2 Pro is billed per megapixel: 2 MP costs $0.03.
        app(UsageCollector::class)->flush(null);

        $usage = AiUsage::query()->where('provider', 'replicate')->sole();
        $this->assertEqualsWithDelta(0.03, (float) $usage->cost_usd, 0.0001);
    }

    public function test_the_aspect_ratio_follows_the_requested_size(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'id' => 'pred-916',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/916.png',
            ]),
            'replicate.delivery/916.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        app(AiManager::class)->generateImage('a cozy owl', '864x1536');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/predictions')
                && ((array) $request->data()['input'])['aspect_ratio'] === '9:16';
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

    public function test_an_unknown_model_falls_back_to_its_fetched_schema(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'acme/custom-image');

        // Replicate exposes enums as separate schema components referenced by
        // the property via allOf/$ref, so the fallback fetch must resolve
        // them - and stay at the old prefer-the-largest-tier behaviour.
        Http::fake([
            'api.replicate.com/v1/models/acme/custom-image' => Http::response([
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
            'api.replicate.com/v1/models/acme/custom-image/predictions' => Http::response([
                'id' => 'pred-custom',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/custom.png',
            ]),
            'replicate.delivery/custom.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
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

    public function test_a_failed_schema_fetch_is_not_cached_for_an_hour(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'acme/custom-image');

        Http::fake([
            'api.replicate.com/v1/models/acme/custom-image' => Http::response('server error', 500),
            'api.replicate.com/v1/models/acme/custom-image/predictions' => Http::response([
                'id' => 'pred-degraded',
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/degraded.png',
            ]),
            'replicate.delivery/degraded.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        app(AiManager::class)->generateImage('a cozy owl', '1024x1024');

        // Two minutes later the failure cache has expired: the next request
        // fetches the schema again instead of running degraded for an hour.
        $this->travel(2)->minutes();

        app(AiManager::class)->generateImage('a cozy owl', '1024x1024');

        $schemaFetches = Http::recorded(
            fn (Request $request): bool => $request->url() === 'https://api.replicate.com/v1/models/acme/custom-image',
        );

        $this->assertCount(2, $schemaFetches);
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
