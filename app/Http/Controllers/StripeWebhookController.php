<?php

namespace App\Http\Controllers;

use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook events. Public and CSRF-exempt; trust comes from
     * the signature check against the raw request body. A signature-verified
     * payment_intent.succeeded starts generation via the shared, idempotent
     * StripePaymentService::markOrderPaidAndStart, the same helper used by
     * read-time reconciliation, so the two paths can never double-fire.
     */
    public function __invoke(Request $request, StripePaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');
        $signature = (string) $request->header('Stripe-Signature');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'Webhook not configured'], 400);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            Log::warning('Stripe webhook signature verification failed: '.$exception->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $paymentIntentId = (string) ($event->data->object->id ?? '');

        if ($paymentIntentId !== '') {
            if ($event->type === 'payment_intent.succeeded') {
                $payments->markOrderPaidAndStart($paymentIntentId);
            } elseif ($event->type === 'payment_intent.payment_failed') {
                $payments->markOrderFailed($paymentIntentId);
            }
        }

        return response()->json(['received' => true]);
    }
}
