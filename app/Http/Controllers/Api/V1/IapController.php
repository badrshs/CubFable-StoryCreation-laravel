<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IapController extends Controller
{
    /**
     * Prepare a draft book for an in-app purchase: create (or reuse) its
     * pending RevenueCat order so the purchase can be traced back even if the
     * webhook's subscriber attributes go missing. Called right before the app
     * shows the store purchase sheet.
     */
    public function intent(Request $request, RevenueCatPaymentService $payments, int $id): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        $order = $payments->createOrReusePendingOrder($book);

        return response()->json([
            'data' => [
                'orderId' => $order->id,
                'productId' => $payments->productId(),
            ],
        ]);
    }

    /**
     * Reconcile a draft book's purchase against RevenueCat, server-side. The
     * client claims nothing; the app calls this right after the store
     * purchase resolves (and as the restore-purchases path) instead of
     * waiting for the webhook.
     */
    public function reconcile(Request $request, RevenueCatPaymentService $payments, int $id): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        $status = $payments->reconcile($book);

        return response()->json([
            'data' => [
                'status' => $status->value,
            ],
        ]);
    }
}
