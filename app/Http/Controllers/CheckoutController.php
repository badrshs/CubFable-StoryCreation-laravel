<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Exceptions\InvalidBookStateException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    use MapsCubfableProps;

    public function __construct(private PaymentManager $payments) {}

    /**
     * Show the checkout page for a draft book, creating (or reusing) its
     * provider-side transaction server-side with the active payment provider.
     * The price is fixed server-side; the client never sends an amount. Books
     * that are no longer awaiting payment go straight to the reader.
     */
    public function show(Request $request, int $id): Response|RedirectResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status !== BookStatus::Draft) {
            return redirect()->route('books.show', ['id' => $book->id]);
        }

        try {
            $checkout = $this->payments->checkoutFor($book);
        } catch (PaymentAlreadyCompletedException|InvalidBookStateException) {
            return redirect()->route('books.show', ['id' => $book->id]);
        }

        return Inertia::render('checkout', [
            'book' => $this->bookProps($book),
            ...Arr::except($checkout, ['amount', 'currency']),
            'amount' => number_format($checkout['amount'] / 100, 2, '.', ''),
            'currency' => strtoupper($checkout['currency']),
        ]);
    }

    /**
     * Reconcile a draft book's payment against its payment provider (fallback
     * for a delayed or not-yet-wired webhook). Returns the current book status
     * as plain JSON so the client can route accordingly.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        return response()->json([
            'status' => $this->payments->reconcile($book)->value,
        ]);
    }
}
