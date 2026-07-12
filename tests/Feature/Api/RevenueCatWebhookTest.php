<?php

namespace Tests\Feature\Api;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RevenueCatWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rc-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('services.revenuecat.webhook_secret', self::SECRET);
        config()->set('services.revenuecat.product_id', 'cubfable_book');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function purchaseEvent(User $user, array $overrides = []): array
    {
        return [
            'event' => array_merge([
                'type' => 'NON_RENEWING_PURCHASE',
                'app_user_id' => (string) $user->id,
                'transaction_id' => 'txn-1000000001',
                'product_id' => 'cubfable_book',
                'price' => 8.99,
                'price_in_purchased_currency' => 8.99,
                'currency' => 'EUR',
                'environment' => 'PRODUCTION',
                'subscriber_attributes' => [],
            ], $overrides),
        ];
    }

    public function test_the_webhook_rejects_a_missing_or_wrong_authorization_header()
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user))
            ->assertStatus(400);

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user), [
            'Authorization' => 'wrong-secret',
        ])->assertStatus(401);

        Queue::assertNotPushed(GenerateStorybookJob::class);
    }

    public function test_the_webhook_rejects_when_no_secret_is_configured()
    {
        config()->set('services.revenuecat.webhook_secret', '');

        $user = User::factory()->create();

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user), [
            'Authorization' => self::SECRET,
        ])->assertStatus(400);
    }

    public function test_a_purchase_with_an_order_id_attribute_activates_the_book()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->pending()->create();

        $response = $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'subscriber_attributes' => [
                'order_id' => ['value' => (string) $order->id],
                'book_id' => ['value' => (string) $book->id],
            ],
        ]), ['Authorization' => self::SECRET]);

        $response->assertOk()->assertJson(['received' => true]);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('txn-1000000001', $order->provider_transaction_id);
        $this->assertSame(899, $order->amount);
        $this->assertSame('eur', $order->currency);

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        $this->assertNotNull($book->paid_at);
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_duplicate_deliveries_are_idempotent()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->pending()->create();

        $payload = $this->purchaseEvent($user, [
            'subscriber_attributes' => ['order_id' => ['value' => (string) $order->id]],
        ]);

        $this->postJson(route('api.v1.webhooks.revenuecat'), $payload, ['Authorization' => self::SECRET])->assertOk();

        $paidAt = $order->refresh()->paid_at;

        $this->travel(5)->minutes();

        $this->postJson(route('api.v1.webhooks.revenuecat'), $payload, ['Authorization' => self::SECRET])->assertOk();

        $this->assertTrue($paidAt->equalTo($order->refresh()->paid_at));
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_a_purchase_with_only_a_book_id_attribute_creates_and_activates_an_order()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'subscriber_attributes' => ['book_id' => ['value' => (string) $book->id]],
        ]), ['Authorization' => self::SECRET])->assertOk();

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        $this->assertDatabaseHas('orders', [
            'book_id' => $book->id,
            'provider' => Order::PROVIDER_REVENUECAT,
            'provider_transaction_id' => 'txn-1000000001',
            'status' => OrderStatus::Paid->value,
        ]);
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_a_purchase_without_attributes_falls_back_to_the_users_single_pending_order()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->pending()->create();

        $this->postJson(
            route('api.v1.webhooks.revenuecat'),
            $this->purchaseEvent($user),
            ['Authorization' => self::SECRET],
        )->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
    }

    public function test_ambiguous_attributeless_purchases_are_left_for_reconcile()
    {
        $user = User::factory()->create();
        $bookA = Book::factory()->for($user)->draft()->create();
        $bookB = Book::factory()->for($user)->draft()->create();
        Order::factory()->for($user)->for($bookA)->revenuecat()->pending()->create();
        Order::factory()->for($user)->for($bookB)->revenuecat()->pending()->create();

        $this->postJson(
            route('api.v1.webhooks.revenuecat'),
            $this->purchaseEvent($user),
            ['Authorization' => self::SECRET],
        )->assertOk();

        $this->assertSame(BookStatus::Draft, $bookA->refresh()->status);
        $this->assertSame(BookStatus::Draft, $bookB->refresh()->status);
        Queue::assertNotPushed(GenerateStorybookJob::class);
    }

    public function test_sandbox_events_are_rejected_unless_allowed()
    {
        config()->set('services.revenuecat.allow_sandbox', false);

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->pending()->create();

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'environment' => 'SANDBOX',
            'subscriber_attributes' => ['order_id' => ['value' => (string) $order->id]],
        ]), ['Authorization' => self::SECRET])->assertOk();

        $this->assertSame(BookStatus::Draft, $book->refresh()->status);

        config()->set('services.revenuecat.allow_sandbox', true);

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'environment' => 'SANDBOX',
            'subscriber_attributes' => ['order_id' => ['value' => (string) $order->id]],
        ]), ['Authorization' => self::SECRET])->assertOk();

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
    }

    public function test_unknown_users_are_acknowledged_without_side_effects()
    {
        $user = User::factory()->create();
        $payload = $this->purchaseEvent($user);
        $payload['event']['app_user_id'] = '999999';

        $this->postJson(route('api.v1.webhooks.revenuecat'), $payload, ['Authorization' => self::SECRET])
            ->assertOk();

        Queue::assertNotPushed(GenerateStorybookJob::class);
    }

    public function test_a_cancellation_fails_the_matching_pending_order()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->pending()->create([
            'provider_transaction_id' => 'txn-cancel-me',
        ]);

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'type' => 'CANCELLATION',
            'transaction_id' => 'txn-cancel-me',
        ]), ['Authorization' => self::SECRET])->assertOk();

        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
    }

    public function test_a_cancellation_never_unpays_a_paid_order()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create();
        $order = Order::factory()->for($user)->for($book)->revenuecat()->paid()->create([
            'provider_transaction_id' => 'txn-paid',
        ]);

        $this->postJson(route('api.v1.webhooks.revenuecat'), $this->purchaseEvent($user, [
            'type' => 'CANCELLATION',
            'transaction_id' => 'txn-paid',
        ]), ['Authorization' => self::SECRET])->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }
}
