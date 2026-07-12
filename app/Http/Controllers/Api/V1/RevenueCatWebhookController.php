<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueCatWebhookController extends Controller
{
    /**
     * Handle RevenueCat webhook events. Public; trust comes from the static
     * Authorization header configured in the RevenueCat dashboard. A verified
     * purchase event starts generation via the shared, idempotent
     * BookPaymentActivator, the same helper the reconcile path uses, so the
     * two paths can never double-fire.
     */
    public function __invoke(Request $request, RevenueCatPaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.revenuecat.webhook_secret');
        $authorization = (string) $request->header('Authorization');

        if ($secret === '' || $authorization === '') {
            return response()->json(['error' => 'Webhook not configured'], 400);
        }

        if (! hash_equals($secret, $authorization)) {
            return response()->json(['error' => 'Invalid authorization'], 401);
        }

        /** @var array<string, mixed> $event */
        $event = (array) $request->json('event', []);

        if ($event !== []) {
            $payments->applyWebhookEvent($event);
        }

        return response()->json(['received' => true]);
    }
}
