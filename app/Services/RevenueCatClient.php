<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the RevenueCat REST API, used by server-side purchase
 * reconciliation. Built on the Http facade so tests can Http::fake() it.
 */
class RevenueCatClient
{
    /**
     * Fetch a subscriber (customer) by app user id. Returns the subscriber
     * payload, or null when RevenueCat does not know the id.
     *
     * @return array<string, mixed>|null
     */
    public function subscriber(string $appUserId): ?array
    {
        $apiKey = (string) config('services.revenuecat.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('REVENUECAT_API_KEY is not set.');
        }

        $baseUrl = rtrim((string) config('services.revenuecat.base_url'), '/');

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->get($baseUrl.'/v1/subscribers/'.rawurlencode($appUserId));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        /** @var array<string, mixed>|null $subscriber */
        $subscriber = $response->json('subscriber');

        return $subscriber;
    }
}
