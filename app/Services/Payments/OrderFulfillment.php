<?php

namespace App\Services\Payments;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;

/**
 * The single place that turns a paid order into a generating book, shared by
 * every provider's webhook, read-time reconciliation, and checkout revisits.
 */
class OrderFulfillment
{
    /**
     * Apply a successful payment for a provider transaction exactly once,
     * then start generation.
     *
     * Atomic and idempotent: only the caller that actually flips the order to
     * paid proceeds (the conditional UPDATE claims the row), so concurrent
     * webhook deliveries and reconciliation cannot double-fire. The book is
     * flipped only while it is still a draft, so a stale event never overwrites
     * an in-progress or completed book.
     *
     * Callers MUST have already established that the payment truly succeeded
     * via a trusted signal (a signature-verified webhook event, or a
     * server-side transaction retrieve), never a client claim.
     */
    public function markPaidAndStart(PaymentProvider $provider, string $transactionId): void
    {
        $claimed = Order::query()
            ->where('provider', $provider)
            ->where('provider_transaction_id', $transactionId)
            ->where('status', '!=', OrderStatus::Paid)
            ->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $bookId = Order::query()
            ->where('provider', $provider)
            ->where('provider_transaction_id', $transactionId)
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
     * Record a failed payment attempt for the order tied to a provider
     * transaction. Never touches paid orders: a late or out-of-order failure
     * event must not undo a completed payment.
     */
    public function markFailed(PaymentProvider $provider, string $transactionId): void
    {
        Order::query()
            ->where('provider', $provider)
            ->where('provider_transaction_id', $transactionId)
            ->where('status', '!=', OrderStatus::Paid)
            ->update(['status' => OrderStatus::Failed->value]);
    }
}
