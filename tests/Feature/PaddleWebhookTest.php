<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaddleWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'pdl_ntfset_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paddle.webhook_secret', self::WEBHOOK_SECRET);
    }

    public function test_the_webhook_route_is_exempt_from_csrf_verification(): void
    {
        // CSRF verification is skipped wholesale while the app environment is
        // "testing", so masquerade as production for this test to make the
        // middleware actually enforce tokens.
        $this->app['env'] = 'production';

        try {
            // A tokenless POST to a regular web route is rejected by CSRF...
            $this->post(route('characters.store'))->assertStatus(419);

            // ...while the same tokenless POST to the webhook route passes the
            // middleware and reaches the controller, which then rejects the
            // missing Paddle signature itself.
            $this->post(route('webhooks.paddle'))
                ->assertBadRequest()
                ->assertJson(['error' => 'Webhook not configured']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_request_without_a_signature_is_rejected(): void
    {
        Queue::fake();

        $response = $this->postRaw((string) json_encode($this->transactionEvent('transaction.completed', 'txn_test_123')));

        $response->assertBadRequest();
        Queue::assertNothingPushed();
    }

    public function test_a_request_with_an_invalid_signature_is_rejected(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('txn_test_123');

        $payload = (string) json_encode($this->transactionEvent('transaction.completed', 'txn_test_123'));
        $timestamp = time();
        $forged = hash_hmac('sha256', $timestamp.':'.$payload, 'pdl_ntfset_wrong_secret');

        $this->postRaw($payload, "ts={$timestamp};h1={$forged}")
            ->assertBadRequest()
            ->assertJson(['error' => 'Invalid signature']);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_request_with_a_stale_timestamp_is_rejected(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('txn_test_123');

        $payload = (string) json_encode($this->transactionEvent('transaction.completed', 'txn_test_123'));
        $timestamp = time() - 3600;
        $signature = hash_hmac('sha256', $timestamp.':'.$payload, self::WEBHOOK_SECRET);

        $this->postRaw($payload, "ts={$timestamp};h1={$signature}")
            ->assertBadRequest()
            ->assertJson(['error' => 'Invalid signature']);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_signed_completed_event_marks_the_order_paid_and_starts_generation(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('txn_test_123');

        $this->postSigned($this->transactionEvent('transaction.completed', 'txn_test_123'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);

        $book->refresh();
        $this->assertSame(BookStatus::Pending, $book->status);
        $this->assertNotNull($book->paid_at);

        Queue::assertPushed(GenerateStorybookJob::class, 1);
        Queue::assertPushed(GenerateStorybookJob::class, fn (GenerateStorybookJob $job): bool => $job->bookId === $book->id);
    }

    public function test_a_duplicate_completed_delivery_changes_nothing_and_dispatches_nothing(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('txn_test_123');

        $event = $this->transactionEvent('transaction.completed', 'txn_test_123');

        $this->postSigned($event)->assertOk();

        $firstPaidAt = $order->refresh()->paid_at;

        // Move the clock so a second write to paid_at would be detectable.
        $this->travel(4)->minutes();

        $this->postSigned($event)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertTrue($order->paid_at->equalTo($firstPaidAt));
        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_a_signed_failed_event_marks_the_order_failed(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('txn_test_123');

        $this->postSigned($this->transactionEvent('transaction.payment_failed', 'txn_test_123'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_paddle_event_never_touches_another_providers_order(): void
    {
        Queue::fake();

        // A Stripe order whose transaction id happens to match the event's.
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $order = Order::factory()->pending()->for($user)->for($book)->create([
            'provider_transaction_id' => 'txn_test_123',
        ]);

        $this->postSigned($this->transactionEvent('transaction.completed', 'txn_test_123'))
            ->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * A draft book with a pending Paddle order tied to a known transaction id.
     *
     * @return array{0: Book, 1: Order}
     */
    private function draftBookAwaitingPayment(string $transactionId): array
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $order = Order::factory()->pending()->paddle()->for($user)->for($book)->create([
            'provider_transaction_id' => $transactionId,
        ]);

        return [$book, $order];
    }

    /**
     * A minimal Paddle event envelope around a transaction object.
     *
     * @return array<string, mixed>
     */
    private function transactionEvent(string $type, string $transactionId): array
    {
        return [
            'event_id' => 'evt_test_1',
            'event_type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'id' => $transactionId,
                'status' => $type === 'transaction.completed' ? 'completed' : 'past_due',
            ],
        ];
    }

    /**
     * Sign the payload exactly the way Paddle does: h1 is an HMAC SHA-256 of
     * "<timestamp>:<raw payload>" with the endpoint secret.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSigned(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$payload, self::WEBHOOK_SECRET);

        return $this->postRaw($payload, "ts={$timestamp};h1={$signature}");
    }

    /**
     * POST a raw body to the webhook route, since the signature covers the
     * exact bytes of the payload.
     */
    private function postRaw(string $payload, ?string $signatureHeader = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signatureHeader !== null) {
            $server['HTTP_PADDLE_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', route('webhooks.paddle'), [], [], [], $server, $payload);
    }
}
