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

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.webhook_secret', self::WEBHOOK_SECRET);
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
            // missing Stripe signature itself.
            $this->post(route('webhooks.stripe'))
                ->assertBadRequest()
                ->assertJson(['error' => 'Webhook not configured']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_request_without_a_signature_is_rejected(): void
    {
        Queue::fake();

        $response = $this->postRaw((string) json_encode($this->paymentIntentEvent('payment_intent.succeeded', 'pi_test_123')));

        $response->assertBadRequest();
        Queue::assertNothingPushed();
    }

    public function test_a_request_with_an_invalid_signature_is_rejected(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('pi_test_123');

        $payload = (string) json_encode($this->paymentIntentEvent('payment_intent.succeeded', 'pi_test_123'));
        $timestamp = time();
        $forged = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_wrong_secret');

        $this->postRaw($payload, "t={$timestamp},v1={$forged}")
            ->assertBadRequest()
            ->assertJson(['error' => 'Invalid signature']);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_signed_succeeded_event_marks_the_order_paid_and_starts_generation(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('pi_test_123');

        $this->postSigned($this->paymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))
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

    public function test_a_duplicate_succeeded_delivery_changes_nothing_and_dispatches_nothing(): void
    {
        Queue::fake();

        [$book, $order] = $this->draftBookAwaitingPayment('pi_test_123');

        $event = $this->paymentIntentEvent('payment_intent.succeeded', 'pi_test_123');

        $this->postSigned($event)->assertOk();

        $firstPaidAt = $order->refresh()->paid_at;

        // Move the clock so a second write to paid_at would be detectable.
        $this->travel(5)->minutes();

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

        [$book, $order] = $this->draftBookAwaitingPayment('pi_test_123');

        $this->postSigned($this->paymentIntentEvent('payment_intent.payment_failed', 'pi_test_123'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * A draft book with a pending order tied to a known PaymentIntent id.
     *
     * @return array{0: Book, 1: Order}
     */
    private function draftBookAwaitingPayment(string $paymentIntentId): array
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $order = Order::factory()->pending()->for($user)->for($book)->create([
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        return [$book, $order];
    }

    /**
     * A minimal Stripe event envelope around a payment_intent object.
     *
     * @return array<string, mixed>
     */
    private function paymentIntentEvent(string $type, string $paymentIntentId): array
    {
        return [
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                ],
            ],
        ];
    }

    /**
     * Sign the payload exactly the way Stripe does: the v1 scheme is an HMAC
     * SHA-256 of "<timestamp>.<raw payload>" with the endpoint secret.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSigned(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return $this->postRaw($payload, "t={$timestamp},v1={$signature}");
    }

    /**
     * POST a raw body to the webhook route, since the signature covers the
     * exact bytes of the payload.
     */
    private function postRaw(string $payload, ?string $signatureHeader = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signatureHeader !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', route('webhooks.stripe'), [], [], [], $server, $payload);
    }
}
