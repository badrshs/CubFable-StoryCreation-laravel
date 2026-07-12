<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Order;

/**
 * The single place that turns a paid order into a generating book, shared by
 * every payment provider (Stripe webhook and reconcile, RevenueCat webhook
 * and reconcile).
 *
 * Atomic and idempotent: only the caller that actually flips the order to
 * paid proceeds (the conditional UPDATE claims the row), so concurrent
 * webhook deliveries and reconciliation cannot double-fire. The book is
 * flipped only while it is still a draft, so a stale event never overwrites
 * an in-progress or completed book.
 *
 * Callers MUST have already established that the payment truly succeeded via
 * a trusted signal (a signature/secret-verified webhook event, or a
 * server-side retrieve from the provider), never a client claim.
 */
class BookPaymentActivator
{
    /**
     * Apply a successful payment exactly once, then start generation.
     * Returns true when this call was the one that claimed the order.
     */
    public function activateOrder(int $orderId): bool
    {
        $claimed = Order::query()
            ->whereKey($orderId)
            ->where('status', '!=', OrderStatus::Paid)
            ->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $bookId = Order::query()->whereKey($orderId)->value('book_id');

        if ($bookId === null) {
            return false;
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

        return true;
    }

    /**
     * Record a failed or refunded payment attempt. A paid order is never
     * un-paid here; refund policy for generated books is handled elsewhere.
     */
    public function failOrder(int $orderId): void
    {
        Order::query()
            ->whereKey($orderId)
            ->where('status', '!=', OrderStatus::Paid)
            ->update(['status' => OrderStatus::Failed->value]);
    }
}
