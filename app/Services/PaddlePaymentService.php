<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\InvalidBookStateException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Book;
use App\Models\Order;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Handles the one-time Paddle Billing payment that unlocks storybook
 * generation, mirroring StripePaymentService method for method. The server
 * creates each transaction with a custom (non-catalog) price from the admin
 * settings, so nothing is managed in the Paddle catalog and the price stays
 * server-side only.
 */
class PaddlePaymentService implements PaymentGateway
{
    /**
     * Paddle transaction statuses that mean the payment went through.
     */
    private const PAID_STATUSES = ['paid', 'completed'];

    /**
     * Paddle transaction statuses a checkout can still be opened for.
     */
    private const OPEN_STATUSES = ['draft', 'ready'];

    public function __construct(private OrderFulfillment $fulfillment) {}

    public function name(): PaymentProvider
    {
        return PaymentProvider::Paddle;
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
     * Create (or reuse) the Paddle transaction for a draft book. Reuses the
     * book's pending transaction while Paddle still lets a checkout complete
     * it; otherwise creates a fresh one and records a pending order. Returns
     * the props the inline Paddle.js checkout needs.
     *
     * @return array{provider: string, transactionId: string, clientToken: string, environment: string, amount: int, currency: string}
     *
     * @throws InvalidBookStateException when the book is not awaiting payment
     * @throws PaymentAlreadyCompletedException when the pending transaction already succeeded (the paid path has already run, so the book is unlocked and no second chargeable transaction is minted)
     */
    public function createOrReuseCheckout(Book $book): array
    {
        if ($book->status !== BookStatus::Draft) {
            throw InvalidBookStateException::notAwaitingPayment();
        }

        $transactionId = null;
        $existingOrder = $this->latestPendingOrderFor($book);

        if ($existingOrder !== null) {
            $status = $this->transactionStatus($existingOrder->provider_transaction_id);

            if (in_array($status, self::PAID_STATUSES, true)) {
                $this->markOrderPaidAndStart($existingOrder->provider_transaction_id);

                throw new PaymentAlreadyCompletedException;
            }

            if (in_array($status, self::OPEN_STATUSES, true)) {
                $transactionId = $existingOrder->provider_transaction_id;
            }
        }

        $transactionId ??= $this->createTransactionAndPendingOrder($book);

        return [
            'provider' => PaymentProvider::Paddle->value,
            'transactionId' => $transactionId,
            'clientToken' => (string) config('services.paddle.client_token'),
            'environment' => $this->environment(),
            'amount' => $this->priceCents(),
            'currency' => $this->priceCurrency(),
        ];
    }

    /**
     * Apply a successful payment for a Paddle transaction exactly once, then
     * start generation (see OrderFulfillment for the atomicity guarantees).
     */
    public function markOrderPaidAndStart(string $transactionId): void
    {
        $this->fulfillment->markPaidAndStart(PaymentProvider::Paddle, $transactionId);
    }

    /**
     * Record a failed payment attempt for the order tied to a transaction.
     */
    public function markOrderFailed(string $transactionId): void
    {
        $this->fulfillment->markFailed(PaymentProvider::Paddle, $transactionId);
    }

    /**
     * Reconcile a draft book's payment against Paddle (fallback for a delayed
     * or not-yet-wired webhook). If the book's transaction has actually been
     * paid (verified server-side with Paddle, not a client claim), unlock the
     * book and start generation. Returns the current book status so the
     * caller can route accordingly.
     */
    public function reconcile(Book $book): BookStatus
    {
        if ($book->status !== BookStatus::Draft) {
            return $book->status;
        }

        $latestOrder = $book->orders()
            ->where('provider', PaymentProvider::Paddle)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestOrder !== null) {
            $status = $this->transactionStatus($latestOrder->provider_transaction_id);

            if (in_array($status, self::PAID_STATUSES, true)) {
                $this->markOrderPaidAndStart($latestOrder->provider_transaction_id);
            }
        }

        return $book->refresh()->status;
    }

    /**
     * Create a fresh Paddle transaction with a custom non-catalog price and
     * record the single pending order for the book. If a concurrent request
     * wins the orders_one_pending_per_book unique index race, reuse the
     * winner's transaction; the just-created one is abandoned (never opened
     * in a checkout, never charged).
     */
    private function createTransactionAndPendingOrder(Book $book): string
    {
        $created = $this->paddle()->post('/transactions', [
            'items' => [[
                'quantity' => 1,
                'price' => [
                    'description' => 'One-time storybook price',
                    'name' => 'Personalized storybook',
                    'unit_price' => [
                        'amount' => (string) $this->priceCents(),
                        'currency_code' => strtoupper($this->priceCurrency()),
                    ],
                    'product' => [
                        'name' => 'CubFable Storybook',
                        'tax_category' => 'standard',
                    ],
                ],
            ]],
            'currency_code' => strtoupper($this->priceCurrency()),
            'custom_data' => [
                'bookId' => (string) $book->id,
                'userId' => (string) $book->user_id,
            ],
        ])->json('data');

        $transactionId = (string) $created['id'];

        try {
            Order::create([
                'user_id' => $book->user_id,
                'book_id' => $book->id,
                'provider' => PaymentProvider::Paddle,
                'provider_transaction_id' => $transactionId,
                'amount' => $this->priceCents(),
                'currency' => $this->priceCurrency(),
                'status' => OrderStatus::Pending,
            ]);

            return $transactionId;
        } catch (UniqueConstraintViolationException) {
            $winner = $this->latestPendingOrderFor($book);

            if ($winner === null) {
                throw new RuntimeException('Failed to create or reuse a pending order.');
            }

            return $winner->provider_transaction_id;
        }
    }

    private function transactionStatus(string $transactionId): string
    {
        return (string) $this->paddle()
            ->get('/transactions/'.$transactionId)
            ->json('data.status');
    }

    private function latestPendingOrderFor(Book $book): ?Order
    {
        return $book->orders()
            ->where('provider', PaymentProvider::Paddle)
            ->where('status', OrderStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function environment(): string
    {
        return config('services.paddle.environment') === 'production' ? 'production' : 'sandbox';
    }

    /**
     * Lazily construct the Paddle API client so the app can boot without a
     * key; it is only needed on payment, webhook, and reconcile paths.
     */
    private function paddle(): PendingRequest
    {
        $apiKey = (string) config('services.paddle.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('PADDLE_API_KEY is not set.');
        }

        $baseUrl = $this->environment() === 'production'
            ? 'https://api.paddle.com'
            : 'https://sandbox-api.paddle.com';

        return Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->throw();
    }
}
