<?php

namespace App\Http\Controllers;

use App\Services\PaddlePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaddleWebhookController extends Controller
{
    /**
     * How far a webhook's signed timestamp may drift before the event is
     * rejected as a possible replay (mirrors stripe-php's default tolerance).
     */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Handle Paddle Billing webhook events. Public and CSRF-exempt; trust
     * comes from the Paddle-Signature check against the raw request body. A
     * signature-verified transaction.completed starts generation via the
     * shared, idempotent PaddlePaymentService::markOrderPaidAndStart, the
     * same helper used by read-time reconciliation, so the two paths can
     * never double-fire.
     */
    public function __invoke(Request $request, PaddlePaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.paddle.webhook_secret');
        $signature = (string) $request->header('Paddle-Signature');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'Webhook not configured'], 400);
        }

        if (! $this->signatureIsValid($signature, $request->getContent(), $secret)) {
            Log::warning('Paddle webhook signature verification failed.');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->json()->all();
        $eventType = (string) ($event['event_type'] ?? '');
        $transactionId = (string) ($event['data']['id'] ?? '');

        if ($transactionId !== '') {
            if (in_array($eventType, ['transaction.completed', 'transaction.paid'], true)) {
                $payments->markOrderPaidAndStart($transactionId);
            } elseif ($eventType === 'transaction.payment_failed') {
                $payments->markOrderFailed($transactionId);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Verify the Paddle-Signature header ("ts=...;h1=...", h1 may repeat
     * during secret rotation): any h1 must equal the HMAC-SHA256 of
     * "{ts}:{rawBody}" with the endpoint secret, and ts must be recent.
     */
    private function signatureIsValid(string $header, string $rawBody, string $secret): bool
    {
        $timestamp = null;
        $hashes = [];

        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 'ts') {
                $timestamp = $value;
            } elseif ($key === 'h1' && $value !== '') {
                $hashes[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $hashes === []) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, $secret);

        foreach ($hashes as $hash) {
            if (hash_equals($expected, $hash)) {
                return true;
            }
        }

        return false;
    }
}
