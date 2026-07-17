<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    public function test_forwarded_proto_https_is_honored_behind_the_proxy(): void
    {
        Route::get('/__scheme_probe', fn () => request()->isSecure() ? 'secure' : 'insecure');

        $this->get('/__scheme_probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('secure');
    }
}
