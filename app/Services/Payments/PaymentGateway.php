<?php

namespace App\Services\Payments;

use App\Enums\BookStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\InvalidBookStateException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Book;

/**
 * One payment provider's side of the one-time charge that unlocks storybook
 * generation. Each implementation owns its provider's API calls and webhook
 * semantics; the shared paid/failed bookkeeping lives in OrderFulfillment so
 * a successful payment is applied exactly once no matter which provider or
 * path (webhook, reconcile, checkout revisit) sees it first.
 */
interface PaymentGateway
{
    public function name(): PaymentProvider;

    /**
     * Create (or reuse) the provider-side transaction for a draft book and
     * return the props the checkout page needs. Always includes 'provider',
     * 'amount' (cents) and 'currency', plus provider-specific keys.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidBookStateException when the book is not awaiting payment
     * @throws PaymentAlreadyCompletedException when the pending transaction already succeeded
     */
    public function createOrReuseCheckout(Book $book): array;

    /**
     * Reconcile a draft book's payment against this provider (fallback for a
     * delayed or not-yet-wired webhook). Only considers orders created with
     * this provider. Returns the current book status.
     */
    public function reconcile(Book $book): BookStatus;

    /**
     * Apply a successful payment exactly once, then start generation. Callers
     * MUST have verified the payment via a trusted signal (signature-verified
     * webhook or server-side retrieve), never a client claim.
     */
    public function markOrderPaidAndStart(string $transactionId): void;

    /**
     * Record a failed payment attempt for this provider's order.
     */
    public function markOrderFailed(string $transactionId): void;
}
