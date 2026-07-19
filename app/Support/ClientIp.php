<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Real visitor IP behind Cloudflare. Traefik does not trust Cloudflare's
 * X-Forwarded-For chain, so $request->ip() resolves to a Cloudflare edge
 * address (162.158.0.0/15 etc.) in production. Cloudflare always carries the
 * visitor's address in CF-Connecting-IP; prefer it whenever present and valid.
 */
class ClientIp
{
    public static function from(Request $request): ?string
    {
        $cloudflare = $request->header('CF-Connecting-IP');

        if (is_string($cloudflare) && filter_var($cloudflare, FILTER_VALIDATE_IP) !== false) {
            return $cloudflare;
        }

        return $request->ip();
    }
}
