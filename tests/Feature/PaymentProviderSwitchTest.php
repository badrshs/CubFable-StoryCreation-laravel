<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use App\Services\PaddlePaymentService;
use App\Services\Payments\PaymentManager;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_active_gateway_follows_the_payment_provider_setting(): void
    {
        $manager = app(PaymentManager::class);

        config()->set('cubfable.payment_provider', 'stripe');
        $this->assertSame(PaymentProvider::Stripe, $manager->active()->name());

        config()->set('cubfable.payment_provider', 'paddle');
        $this->assertSame(PaymentProvider::Paddle, $manager->active()->name());

        // An unknown value falls back to Stripe instead of breaking checkout.
        config()->set('cubfable.payment_provider', 'bogus');
        $this->assertSame(PaymentProvider::Stripe, $manager->active()->name());
    }

    public function test_reconcile_uses_the_orders_provider_not_the_active_setting(): void
    {
        config()->set('cubfable.payment_provider', 'stripe');

        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        Order::factory()->pending()->paddle()->for($user)->for($book)->create();

        $this->mock(PaddlePaymentService::class, function (MockInterface $mock) use ($book): void {
            $mock->shouldReceive('reconcile')
                ->once()
                ->withArgs(fn (Book $candidate): bool => $candidate->is($book))
                ->andReturn(BookStatus::Draft);
        });
        $this->mock(StripePaymentService::class)->shouldNotReceive('reconcile');

        $this->assertSame(BookStatus::Draft, app(PaymentManager::class)->reconcile($book));
    }

    public function test_checkout_settles_a_stale_pending_order_from_the_other_provider(): void
    {
        config()->set('cubfable.payment_provider', 'paddle');

        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $staleOrder = Order::factory()->pending()->for($user)->for($book)->create();

        // The old Stripe order gets one last server-side check (still unpaid),
        // then the new Paddle checkout takes over.
        $this->mock(StripePaymentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->once()->andReturn(BookStatus::Draft);
        });
        $this->mock(PaddlePaymentService::class, function (MockInterface $mock) use ($book): void {
            $mock->shouldReceive('createOrReuseCheckout')
                ->once()
                ->withArgs(fn (Book $candidate): bool => $candidate->is($book))
                ->andReturn(['provider' => 'paddle', 'transactionId' => 'txn_new', 'amount' => 799, 'currency' => 'eur']);
        });

        $checkout = app(PaymentManager::class)->checkoutFor($book);

        $this->assertSame('paddle', $checkout['provider']);
        $this->assertSame(OrderStatus::Failed, $staleOrder->refresh()->status);
    }

    public function test_checkout_surfaces_a_stale_order_that_actually_got_paid(): void
    {
        config()->set('cubfable.payment_provider', 'paddle');

        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $staleOrder = Order::factory()->pending()->for($user)->for($book)->create();

        // Reconciling the old Stripe order discovers the payment went through,
        // so no new Paddle transaction is minted and the caller redirects to
        // the reader.
        $this->mock(StripePaymentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->once()->andReturn(BookStatus::Pending);
        });
        $this->mock(PaddlePaymentService::class)->shouldNotReceive('createOrReuseCheckout');

        try {
            app(PaymentManager::class)->checkoutFor($book);
            $this->fail('Expected PaymentAlreadyCompletedException was not thrown.');
        } catch (PaymentAlreadyCompletedException) {
            // The paid order is left untouched for the webhook to finalize.
            $this->assertSame(OrderStatus::Pending, $staleOrder->refresh()->status);
        }
    }

    public function test_checkout_with_a_matching_pending_order_delegates_without_touching_it(): void
    {
        config()->set('cubfable.payment_provider', 'stripe');

        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $order = Order::factory()->pending()->for($user)->for($book)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createOrReuseCheckout')
                ->once()
                ->andReturn(['provider' => 'stripe', 'clientSecret' => 'sec', 'publishableKey' => 'pk', 'amount' => 799, 'currency' => 'eur']);
            $mock->shouldNotReceive('reconcile');
        });

        $checkout = app(PaymentManager::class)->checkoutFor($book);

        $this->assertSame('stripe', $checkout['provider']);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }
}
