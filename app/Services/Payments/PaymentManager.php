<?php

namespace App\Services\Payments;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\InvalidBookStateException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Book;
use App\Services\PaddlePaymentService;
use App\Services\StripePaymentService;

/**
 * Picks the payment gateway for each operation: new checkouts follow the
 * admin-selected active provider, while existing orders always reconcile
 * against the provider they were created with, so switching providers never
 * strands an in-flight payment.
 */
class PaymentManager
{
    public function active(): PaymentGateway
    {
        return $this->named($this->activeProvider());
    }

    /**
     * Container-resolved so test mocks of the concrete services intercept.
     */
    public function named(PaymentProvider $provider): PaymentGateway
    {
        return match ($provider) {
            PaymentProvider::Stripe => app(StripePaymentService::class),
            PaymentProvider::Paddle => app(PaddlePaymentService::class),
        };
    }

    /**
     * The checkout props for a draft book, from the active provider. If the
     * admin switched providers while this book had a pending order on the old
     * one, that order is settled first: applied if it actually got paid,
     * otherwise marked failed to free the one-pending-per-book slot. A stale
     * transaction that still gets paid later (say, in a forgotten tab) is not
     * lost either: the webhook's markPaidAndStart claims any non-paid order,
     * including a failed one.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidBookStateException when the book is not awaiting payment
     * @throws PaymentAlreadyCompletedException when a pending transaction already succeeded
     */
    public function checkoutFor(Book $book): array
    {
        $activeProvider = $this->activeProvider();

        $stale = $book->orders()
            ->where('status', OrderStatus::Pending)
            ->where('provider', '!=', $activeProvider)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($stale !== null) {
            if ($this->named($stale->provider)->reconcile($book) !== BookStatus::Draft) {
                throw new PaymentAlreadyCompletedException;
            }

            $stale->update(['status' => OrderStatus::Failed->value]);
        }

        return $this->named($activeProvider)->createOrReuseCheckout($book);
    }

    /**
     * Reconcile a draft book's payment against the provider of its latest
     * order (regardless of the currently active provider). Books with no
     * orders fall through to the active provider, which simply reports the
     * current status.
     */
    public function reconcile(Book $book): BookStatus
    {
        if ($book->status !== BookStatus::Draft) {
            return $book->status;
        }

        $latestOrder = $book->orders()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestOrder === null) {
            return $this->active()->reconcile($book);
        }

        return $this->named($latestOrder->provider)->reconcile($book);
    }

    /**
     * The admin-selected provider for new checkouts; an unknown value falls
     * back to Stripe instead of breaking checkout.
     */
    private function activeProvider(): PaymentProvider
    {
        return PaymentProvider::tryFrom((string) config('cubfable.payment_provider')) ?? PaymentProvider::Stripe;
    }
}
