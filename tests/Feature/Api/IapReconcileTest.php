<?php

namespace Tests\Feature\Api;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IapReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('services.revenuecat.api_key', 'rc-api-key');
        config()->set('services.revenuecat.product_id', 'cubfable_book');
    }

    /**
     * @param  array<int, array<string, mixed>>  $purchases
     */
    private function fakeSubscriber(array $purchases): void
    {
        Http::fake([
            'api.revenuecat.com/v1/subscribers/*' => Http::response([
                'subscriber' => [
                    'non_subscriptions' => [
                        'cubfable_book' => $purchases,
                    ],
                ],
            ]),
        ]);
    }

    public function test_an_unconsumed_purchase_activates_the_book()
    {
        $this->fakeSubscriber([
            ['id' => 'txn-restore-1', 'purchase_date' => '2026-07-12T10:00:00Z', 'is_sandbox' => false],
        ]);

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]));

        $response->assertOk()->assertJsonPath('data.status', BookStatus::Pending->value);

        $this->assertDatabaseHas('orders', [
            'book_id' => $book->id,
            'provider' => Order::PROVIDER_REVENUECAT,
            'provider_transaction_id' => 'txn-restore-1',
            'status' => OrderStatus::Paid->value,
        ]);
        Queue::assertPushed(GenerateStorybookJob::class, 1);
    }

    public function test_consumed_transactions_cannot_unlock_a_second_book()
    {
        $this->fakeSubscriber([
            ['id' => 'txn-used', 'purchase_date' => '2026-07-12T10:00:00Z', 'is_sandbox' => false],
        ]);

        $user = User::factory()->create();
        $paidBook = Book::factory()->for($user)->complete()->create();
        Order::factory()->for($user)->for($paidBook)->revenuecat()->paid()->create([
            'provider_transaction_id' => 'txn-used',
        ]);

        $secondBook = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $secondBook->id]));

        $response->assertOk()->assertJsonPath('data.status', BookStatus::Draft->value);
        Queue::assertNotPushed(GenerateStorybookJob::class);
    }

    public function test_the_oldest_unconsumed_purchase_is_applied_first()
    {
        $this->fakeSubscriber([
            ['id' => 'txn-newer', 'purchase_date' => '2026-07-12T12:00:00Z', 'is_sandbox' => false],
            ['id' => 'txn-older', 'purchase_date' => '2026-07-11T09:00:00Z', 'is_sandbox' => false],
        ]);

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]))->assertOk();

        $this->assertDatabaseHas('orders', [
            'book_id' => $book->id,
            'provider_transaction_id' => 'txn-older',
            'status' => OrderStatus::Paid->value,
        ]);
    }

    public function test_reconcile_short_circuits_for_non_draft_books_without_calling_revenuecat()
    {
        Http::fake();

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->generating()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]));

        $response->assertOk()->assertJsonPath('data.status', BookStatus::Generating->value);
        Http::assertNothingSent();
    }

    public function test_no_purchases_leaves_the_draft_untouched()
    {
        $this->fakeSubscriber([]);

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]))
            ->assertOk()
            ->assertJsonPath('data.status', BookStatus::Draft->value);
    }

    public function test_sandbox_purchases_are_ignored_unless_allowed()
    {
        $this->fakeSubscriber([
            ['id' => 'txn-sandbox', 'purchase_date' => '2026-07-12T10:00:00Z', 'is_sandbox' => true],
        ]);

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->draft()->create();
        Sanctum::actingAs($user);

        config()->set('services.revenuecat.allow_sandbox', false);
        $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]))
            ->assertOk()
            ->assertJsonPath('data.status', BookStatus::Draft->value);

        config()->set('services.revenuecat.allow_sandbox', true);
        $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $book->id]))
            ->assertOk()
            ->assertJsonPath('data.status', BookStatus::Pending->value);
    }

    public function test_reconcile_is_owner_scoped()
    {
        $user = User::factory()->create();
        $foreign = Book::factory()->draft()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.iap.reconcile', ['id' => $foreign->id]))->assertNotFound();
    }
}
