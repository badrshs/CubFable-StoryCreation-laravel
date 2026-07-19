<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordDeviceIdentity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Coolify's Traefik proxy (and Cloudflare), which terminate TLS and
        // forward plain HTTP to the container. Trust the forwarded headers so Laravel
        // detects HTTPS and generates https:// asset URLs instead of mixed content.
        $middleware->trustProxies(at: '*');

        // cf_fp is written by client-side JavaScript (the browser fingerprint),
        // so it arrives unencrypted and must be exempt or the server reads null.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'cf_fp']);

        $middleware->preventRequestForgery(except: ['webhooks/stripe']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RecordDeviceIdentity::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
