<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidBookStateException;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles the one-time in-app purchase (Apple/Google via RevenueCat) that
 * unlocks storybook generation on mobile. Mirrors the Stripe philosophy:
 * trust only comes from the secret-verified webhook or a server-side lookup
 * against the RevenueCat API, never from a client claim, and activation runs
 * through the shared idempotent BookPaymentActivator.
 *
 * Consumable purchases carry no per-purchase metadata, so the target book
 * travels out-of-band: a pending order is created before the store purchase
 * (iap/intent) and the purchase's subscriber attributes carry book_id and
 * order_id back on the webhook. Each store transaction id is consumed exactly
 * once (unique column), so a purchase can never unlock two books.
 */
class RevenueCatPaymentService
{
    public function __construct(
        private BookPaymentActivator $activator,
        private RevenueCatClient $client,
    ) {}

    /**
     * The store product identifier the mobile app purchases.
     */
    public function productId(): string
    {
        return (string) config('services.revenuecat.product_id');
    }

    /**
     * Create (or reuse) the pending RevenueCat order for a draft book, called
     * right before the app shows the purchase sheet. A stale pending Stripe
     * order (a web checkout the user abandoned) is superseded; its
     * PaymentIntent is never confirmed, so it can never charge.
     *
     * @throws InvalidBookStateException when the book is not awaiting payment
     */
    public function createOrReusePendingOrder(Book $book): Order
    {
        if ($book->status !== BookStatus::Draft) {
            throw InvalidBookStateException::notAwaitingPayment();
        }

        $pending = $this->latestPendingOrderFor($book);

        if ($pending !== null) {
            if ($pending->provider === Order::PROVIDER_REVENUECAT) {
                return $pending;
            }

            $pending->update(['status' => OrderStatus::Failed->value]);
        }

        try {
            return Order::create([
                'user_id' => $book->user_id,
                'book_id' => $book->id,
                'provider' => Order::PROVIDER_REVENUECAT,
                'provider_transaction_id' => null,
                'stripe_payment_intent_id' => null,
                'amount' => $this->displayPriceCents(),
                'currency' => $this->displayPriceCurrency(),
                'status' => OrderStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            $winner = $this->latestPendingOrderFor($book);

            if ($winner === null) {
                throw new RuntimeException('Failed to create or reuse a pending order.');
            }

            return $winner;
        }
    }

    /**
     * Apply a verified webhook event. The caller (the webhook controller) has
     * already checked the shared-secret Authorization header.
     *
     * @param  array<string, mixed>  $event
     */
    public function applyWebhookEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');

        if ($type === 'CANCELLATION') {
            $this->failByTransactionId((string) ($event['transaction_id'] ?? ''));

            return;
        }

        if (! in_array($type, ['NON_RENEWING_PURCHASE', 'INITIAL_PURCHASE'], true)) {
            return;
        }

        if (! $this->environmentAllowed((string) ($event['environment'] ?? 'PRODUCTION'))) {
            Log::warning('RevenueCat webhook: sandbox event rejected in production.');

            return;
        }

        $transactionId = (string) ($event['transaction_id'] ?? '');

        if ($transactionId === '') {
            return;
        }

        // Idempotency: a transaction already recorded (duplicate delivery, or
        // reconcile got there first) is a clean no-op.
        if (Order::query()->where('provider_transaction_id', $transactionId)->exists()) {
            return;
        }

        $user = User::query()->find((int) ($event['app_user_id'] ?? 0));

        if ($user === null) {
            Log::warning("RevenueCat webhook: unknown app_user_id for transaction {$transactionId}.");

            return;
        }

        $order = $this->resolveTargetOrder($user, $event);

        if ($order === null) {
            Log::warning("RevenueCat webhook: no order resolved for transaction {$transactionId}; reconcile will pick it up.");

            return;
        }

        if (! $this->stampTransaction($order, $transactionId, $event)) {
            return;
        }

        $this->activator->activateOrder($order->id);
    }

    /**
     * Reconcile a draft book's payment against RevenueCat (fallback for a
     * delayed or lost webhook, and the effective restore-purchases path).
     * Any store transaction not yet consumed by an order is an unused
     * purchase credit; the oldest one is applied to this book. Verified
     * server-side with RevenueCat, never a client claim.
     */
    public function reconcile(Book $book): BookStatus
    {
        if ($book->status !== BookStatus::Draft) {
            return $book->status;
        }

        $subscriber = $this->client->subscriber((string) $book->user_id);

        if ($subscriber === null) {
            return $book->status;
        }

        /** @var array<int, array<string, mixed>> $purchases */
        $purchases = $subscriber['non_subscriptions'][$this->productId()] ?? [];

        $purchases = array_values(array_filter(
            $purchases,
            fn (array $purchase): bool => $this->environmentAllowed(
                ($purchase['is_sandbox'] ?? false) ? 'SANDBOX' : 'PRODUCTION',
            ),
        ));

        usort(
            $purchases,
            fn (array $a, array $b): int => strcmp((string) ($a['purchase_date'] ?? ''), (string) ($b['purchase_date'] ?? '')),
        );

        $transactionIds = array_map(fn (array $purchase): string => (string) ($purchase['id'] ?? ''), $purchases);
        $transactionIds = array_values(array_filter($transactionIds, fn (string $id): bool => $id !== ''));

        if ($transactionIds === []) {
            return $book->status;
        }

        $consumed = Order::query()
            ->whereIn('provider_transaction_id', $transactionIds)
            ->pluck('provider_transaction_id')
            ->all();

        $unconsumed = array_values(array_diff($transactionIds, $consumed));

        if ($unconsumed === []) {
            return $book->status;
        }

        $order = $this->createOrReusePendingOrder($book);

        if ($this->stampTransaction($order, $unconsumed[0], null)) {
            $this->activator->activateOrder($order->id);
        }

        return $book->refresh()->status;
    }

    /**
     * Record the store transaction on the order. The unique column arbitrates
     * races: whoever stamps first wins, the loser backs off cleanly.
     *
     * @param  array<string, mixed>|null  $event
     */
    private function stampTransaction(Order $order, string $transactionId, ?array $event): bool
    {
        $order->provider_transaction_id = $transactionId;

        $price = $event['price_in_purchased_currency'] ?? $event['price'] ?? null;
        $currency = $event['currency'] ?? null;

        if (is_numeric($price) && (float) $price > 0) {
            $order->amount = (int) round((float) $price * 100);
        }

        if (is_string($currency) && $currency !== '') {
            $order->currency = strtolower($currency);
        }

        try {
            $order->save();

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Find the order a purchase event should apply to: the order_id attribute
     * stamped by the app, else the book_id attribute, else the user's single
     * pending RevenueCat order.
     *
     * @param  array<string, mixed>  $event
     */
    private function resolveTargetOrder(User $user, array $event): ?Order
    {
        $attributes = $event['subscriber_attributes'] ?? [];

        $orderId = (int) ($attributes['order_id']['value'] ?? 0);

        if ($orderId > 0) {
            $order = Order::query()
                ->whereKey($orderId)
                ->where('user_id', $user->id)
                ->where('provider', Order::PROVIDER_REVENUECAT)
                ->where('status', OrderStatus::Pending)
                ->first();

            if ($order !== null) {
                return $order;
            }
        }

        $bookId = (int) ($attributes['book_id']['value'] ?? 0);

        if ($bookId > 0) {
            $book = $user->books()->whereKey($bookId)->first();

            if ($book !== null) {
                $pending = $this->latestPendingOrderFor($book);

                if ($pending !== null && $pending->provider === Order::PROVIDER_REVENUECAT) {
                    return $pending;
                }

                if ($book->status === BookStatus::Draft) {
                    return $this->createOrReusePendingOrder($book);
                }
            }
        }

        $pendingOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('provider', Order::PROVIDER_REVENUECAT)
            ->where('status', OrderStatus::Pending)
            ->get();

        return $pendingOrders->count() === 1 ? $pendingOrders->first() : null;
    }

    private function failByTransactionId(string $transactionId): void
    {
        if ($transactionId === '') {
            return;
        }

        $orderId = Order::query()
            ->where('provider_transaction_id', $transactionId)
            ->value('id');

        if ($orderId !== null) {
            $this->activator->failOrder($orderId);
        }
    }

    private function environmentAllowed(string $environment): bool
    {
        return strtoupper($environment) !== 'SANDBOX'
            || (bool) config('services.revenuecat.allow_sandbox');
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
     * The store decides the real charge; this is only the display fallback
     * recorded until the purchase event carries the actual price.
     */
    private function displayPriceCents(): int
    {
        $cents = (int) config('cubfable.price_cents');

        return $cents > 0 ? $cents : 799;
    }

    private function displayPriceCurrency(): string
    {
        $currency = strtolower((string) config('cubfable.price_currency'));

        return $currency === '' ? 'eur' : $currency;
    }
}
