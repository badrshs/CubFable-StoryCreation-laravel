<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidBookStateException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Handles the one-time Stripe payment that unlocks storybook generation.
 * Shared by checkout, the Stripe webhook, and read-time reconciliation so a
 * successful payment is applied exactly once no matter which path sees it first.
 */
class StripePaymentService
{
    private ?StripeClient $client = null;

    /**
     * Safe to expose to the client (the publishable key is not a secret).
     */
    public function publishableKey(): string
    {
        return (string) config('services.stripe.key');
    }

    /**
     * The price is server-side only; the client never sends an amount.
     */
    public function priceCents(): int
    {
        $cents = (int) config('cubfable.price_cents');

        return $cents > 0 ? $cents : 799;
    }

    public function priceCurrency(): string
    {
        $currency = strtolower((string) config('cubfable.price_currency'));

        return $currency === '' ? 'eur' : $currency;
    }

    /**
     * Create (or reuse) the Stripe PaymentIntent for a draft book. Reuses the
     * book's pending PaymentIntent when it is still confirmable; otherwise
     * creates a fresh one and records a pending order.
     *
     * @return array{clientSecret: string, publishableKey: string, amount: int, currency: string}
     *
     * @throws InvalidBookStateException when the book is not awaiting payment
     * @throws PaymentAlreadyCompletedException when the pending intent already succeeded (the paid path has already run, so the book is unlocked and no second chargeable intent is minted)
     */
    public function createOrReusePaymentIntent(Book $book): array
    {
        if ($book->status !== BookStatus::Draft) {
            throw InvalidBookStateException::notAwaitingPayment();
        }

        $intent = null;
        $existingOrder = $this->latestPendingOrderFor($book);

        if ($existingOrder !== null) {
            $retrieved = $this->stripe()->paymentIntents->retrieve($existingOrder->stripe_payment_intent_id);

            if ($retrieved->status === PaymentIntent::STATUS_SUCCEEDED) {
                $this->markOrderPaidAndStart($existingOrder->stripe_payment_intent_id);

                throw new PaymentAlreadyCompletedException;
            }

            if ($retrieved->status !== PaymentIntent::STATUS_CANCELED) {
                $intent = $retrieved;
            }
        }

        $intent ??= $this->createIntentAndPendingOrder($book);

        return [
            'clientSecret' => (string) $intent->client_secret,
            'publishableKey' => $this->publishableKey(),
            'amount' => $this->priceCents(),
            'currency' => $this->priceCurrency(),
        ];
    }

    /**
     * Apply a successful payment for a PaymentIntent exactly once, then start
     * generation. This is the single place that turns a paid order into a
     * generating book, shared by the Stripe webhook and read-time reconciliation.
     *
     * Atomic and idempotent: only the caller that actually flips the order to
     * paid proceeds (the conditional UPDATE claims the row), so concurrent
     * webhook deliveries and reconciliation cannot double-fire. The book is
     * flipped only while it is still a draft, so a stale event never overwrites
     * an in-progress or completed book.
     *
     * Callers MUST have already established that the payment truly succeeded
     * via a trusted signal (a signature-verified webhook event, or a
     * server-side PaymentIntent retrieve), never a client claim.
     */
    public function markOrderPaidAndStart(string $paymentIntentId): void
    {
        $claimed = Order::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->where('status', '!=', OrderStatus::Paid)
            ->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $bookId = Order::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->value('book_id');

        if ($bookId === null) {
            return;
        }

        $flipped = Book::query()
            ->whereKey($bookId)
            ->where('status', BookStatus::Draft)
            ->update([
                'status' => BookStatus::Pending->value,
                'paid_at' => now(),
            ]);

        if ($flipped === 1) {
            GenerateStorybookJob::dispatch($bookId);
        }
    }

    /**
     * Record a failed payment attempt for the order tied to a PaymentIntent.
     */
    public function markOrderFailed(string $paymentIntentId): void
    {
        Order::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->update(['status' => OrderStatus::Failed->value]);
    }

    /**
     * Reconcile a draft book's payment against Stripe (fallback for a delayed
     * or not-yet-wired webhook). If the book's PaymentIntent has actually
     * succeeded (verified server-side with Stripe, not a client claim), unlock
     * the book and start generation. Returns the current book status so the
     * caller can route accordingly.
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

        if ($latestOrder !== null) {
            $retrieved = $this->stripe()->paymentIntents->retrieve($latestOrder->stripe_payment_intent_id);

            if ($retrieved->status === PaymentIntent::STATUS_SUCCEEDED) {
                $this->markOrderPaidAndStart($latestOrder->stripe_payment_intent_id);
            }
        }

        return $book->refresh()->status;
    }

    /**
     * Create a fresh PaymentIntent and record the single pending order for the
     * book. If a concurrent request wins the orders_one_pending_per_book unique
     * index race, reuse the winner's intent; the just-created intent is
     * abandoned (never confirmed, never charged).
     */
    private function createIntentAndPendingOrder(Book $book): PaymentIntent
    {
        $created = $this->stripe()->paymentIntents->create([
            'amount' => $this->priceCents(),
            'currency' => $this->priceCurrency(),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'bookId' => (string) $book->id,
                'userId' => (string) $book->user_id,
            ],
        ]);

        try {
            Order::create([
                'user_id' => $book->user_id,
                'book_id' => $book->id,
                'stripe_payment_intent_id' => $created->id,
                'amount' => $this->priceCents(),
                'currency' => $this->priceCurrency(),
                'status' => OrderStatus::Pending,
            ]);

            return $created;
        } catch (UniqueConstraintViolationException) {
            $winner = $this->latestPendingOrderFor($book);

            if ($winner === null) {
                throw new RuntimeException('Failed to create or reuse a pending order.');
            }

            return $this->stripe()->paymentIntents->retrieve($winner->stripe_payment_intent_id);
        }
    }

    private function latestPendingOrderFor(Book $book): ?Order
    {
        return $book->orders()
            ->where('status', OrderStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Lazily construct the Stripe client so the app can boot without a key;
     * the client is only needed on payment, webhook, and reconcile paths.
     */
    private function stripe(): StripeClient
    {
        if ($this->client === null) {
            $secret = (string) config('services.stripe.secret');

            if ($secret === '') {
                throw new RuntimeException('STRIPE_SECRET_KEY is not set.');
            }

            $this->client = new StripeClient($secret);
        }

        return $this->client;
    }
}
