<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IapIntentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.revenuecat.product_id', 'cubfable_book');
    }

    public function test_intent_creates_a_pending_revenuecat_order_for_a_draft()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.iap.intent', ['id' => $book->id]));

        $response->assertOk()->assertJsonPath('data.productId', 'cubfable_book');

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.orderId'),
            'book_id' => $book->id,
            'provider' => Order::PROVIDER_REVENUECAT,
            'provider_transaction_id' => null,
            'status' => OrderStatus::Pending->value,
        ]);
    }

    public function test_intent_reuses_the_existing_pending_revenuecat_order()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson(route('api.v1.books.iap.intent', ['id' => $book->id]))->json('data.orderId');
        $second = $this->postJson(route('api.v1.books.iap.intent', ['id' => $book->id]))->json('data.orderId');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_intent_supersedes_an_abandoned_stripe_checkout()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        $stripeOrder = Order::factory()->for($user)->for($book)->pending()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.iap.intent', ['id' => $book->id]));

        $response->assertOk();
        $this->assertSame(OrderStatus::Failed, $stripeOrder->refresh()->status);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.orderId'),
            'provider' => Order::PROVIDER_REVENUECAT,
            'status' => OrderStatus::Pending->value,
        ]);
    }

    public function test_intent_rejects_books_that_are_not_awaiting_payment()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.iap.intent', ['id' => $book->id]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_book_state');
    }

    public function test_intent_is_owner_scoped()
    {
        $user = User::factory()->create();
        $foreign = Book::factory()->draft()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.iap.intent', ['id' => $foreign->id]))->assertNotFound();
    }

    public function test_the_one_pending_order_per_book_index_survived_the_migration()
    {
        $book = Book::factory()->draft()->create();
        Order::factory()->for($book->user)->for($book)->revenuecat()->pending()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->for($book->user)->for($book)->revenuecat()->pending()->create();
    }
}
