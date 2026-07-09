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

class PiapiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'piapi');
        config()->set('cubfable.ai.models.image.piapi', 'Qubico/flux1-dev-advanced');
        config()->set('cubfable.ai.piapi_base_url', 'https://api.piapi.ai');
        config()->set('cubfable.ai.keys.piapi', 'test-key');

        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_a_reference_image_runs_the_kontext_edit_task(): void
    {
        Storage::disk('public')->put('characters/1/photo.png', (string) base64_decode(self::PNG_BASE64, true));

        Http::fake([
            'api.piapi.ai/api/v1/task/task-123' => Http::response([
                'code' => 200,
                'data' => [
                    'task_id' => 'task-123',
                    'status' => 'success',
                    'output' => ['image_url' => 'https://img.piapi.ai/result.png'],
                ],
            ]),
            'api.piapi.ai/api/v1/task' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-123', 'status' => 'pending'],
            ]),
            'img.piapi.ai/result.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage(
            'a watercolor fox at dawn',
            '1536x1024',
            [new ImageReference('characters/1/photo.png', 'Mia')],
        );

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/task') || $request->method() !== 'POST') {
                return false;
            }

            $data = $request->data();

            return $data['model'] === 'Qubico/flux1-dev-advanced'
                && $data['task_type'] === 'kontext'
                && str_starts_with((string) $data['input']['image'], 'data:image/')
                && $data['input']['width'] === 1536
                && $data['input']['height'] === 1024
                && $request->header('X-API-Key')[0] === 'test-key';
        });

        app(UsageCollector::class)->flush(null);
        $this->assertSame(1, AiUsage::query()->where('provider', 'piapi')->where('kind', 'image')->count());
    }

    public function test_no_reference_falls_back_to_txt2img(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task/task-9' => Http::response([
                'code' => 200,
                'data' => [
                    'task_id' => 'task-9',
                    'status' => 'completed',
                    'output' => ['image_urls' => ['https://img.piapi.ai/result.png']],
                ],
            ]),
            'api.piapi.ai/api/v1/task' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-9', 'status' => 'pending'],
            ]),
            'img.piapi.ai/result.png' => Http::response((string) base64_decode(self::PNG_BASE64, true)),
        ]);

        $bytes = app(AiManager::class)->generateImage('a watercolor fox at dawn', '1024x1536');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/task') || $request->method() !== 'POST') {
                return false;
            }

            $data = $request->data();

            return $data['task_type'] === 'txt2img' && ! array_key_exists('image', $data['input']);
        });
    }

    public function test_polling_waits_out_processing_states(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task/task-slow' => Http::sequence()
                ->push(['code' => 200, 'data' => ['task_id' => 'task-slow', 'status' => 'processing']])
                ->push(['code' => 200, 'data' => [
                    'task_id' => 'task-slow',
                    'status' => 'success',
                    'task_result' => ['task_output' => ['image_base64' => self::PNG_BASE64]],
                ]]),
            'api.piapi.ai/api/v1/task' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-slow', 'status' => 'pending'],
            ]),
        ]);

        $bytes = app(AiManager::class)->generateImage('a cozy owl', '1024x1024');

        $this->assertSame(base64_decode(self::PNG_BASE64), $bytes);
    }

    public function test_a_failed_task_throws(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task/task-bad' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-bad', 'status' => 'failed', 'error' => ['message' => 'GPU exploded']],
            ]),
            'api.piapi.ai/api/v1/task' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-bad', 'status' => 'pending'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        app(AiManager::class)->generateImage('a cozy owl', '1024x1024');
    }

    public function test_a_moderation_failure_feeds_the_safety_ladder(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task/task-mod' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-mod', 'status' => 'failed', 'error' => ['message' => 'prompt flagged as NSFW']],
            ]),
            'api.piapi.ai/api/v1/task' => Http::response([
                'code' => 200,
                'data' => ['task_id' => 'task-mod', 'status' => 'pending'],
            ]),
        ]);

        $this->expectException(ImageContentRejectedException::class);

        app(AiManager::class)->generateImage('a cozy owl', '1024x1024');
    }
}
