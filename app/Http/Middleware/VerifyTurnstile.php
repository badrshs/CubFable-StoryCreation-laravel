<?php

namespace App\Http\Middleware;

use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the Cloudflare Turnstile token on bot-sensitive POST endpoints
 * (login, register, forgot password). Skips entirely when no secret key is
 * configured, so local development and the test suite need no Cloudflare
 * account. When Cloudflare itself is unreachable the request is allowed
 * through: losing bot protection for a moment beats locking humans out.
 */
class VerifyTurnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.turnstile.secret_key');

        if ($secret === '') {
            return $next($request);
        }

        $token = $request->input('cf-turnstile-response');

        if (! is_string($token) || $token === '') {
            $this->fail();
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::VERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => ClientIp::from($request),
            ]);
        } catch (ConnectionException) {
            return $next($request);
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $this->fail();
        }

        return $next($request);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'turnstile' => __('Please confirm you are human, then try again.'),
        ]);
    }
}
