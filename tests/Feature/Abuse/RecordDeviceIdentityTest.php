<?php

namespace Tests\Feature\Abuse;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class RecordDeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function deviceCookieFromResponse($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'cf_did') {
                return $cookie;
            }
        }

        return null;
    }

    public function test_guest_response_queues_a_long_lived_httponly_device_cookie(): void
    {
        $response = $this->get(route('login'));

        $cookie = $this->deviceCookieFromResponse($response);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNotSame('', (string) $cookie->getValue());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_authenticated_request_records_device_fingerprint_and_ip(): void
    {
        $user = User::factory()->create();
        $deviceId = (string) Str::uuid();
        $fingerprint = str_repeat('ab', 16);

        $this->actingAs($user)
            ->withCookie('cf_did', $deviceId)
            ->withUnencryptedCookie('cf_fp', $fingerprint)
            ->get(route('books.index'))
            ->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'fingerprint' => $fingerprint,
        ]);
        $this->assertDatabaseHas('user_ips', [
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ]);
    }

    public function test_guest_request_records_nothing(): void
    {
        $this->withCookie('cf_did', (string) Str::uuid())
            ->get(route('login'))
            ->assertOk();

        $this->assertDatabaseCount('user_devices', 0);
        $this->assertDatabaseCount('user_ips', 0);
    }

    public function test_recording_is_throttled_per_user_and_device(): void
    {
        $this->freezeSecond();

        $user = User::factory()->create();
        $deviceId = (string) Str::uuid();
        $firstSeen = now();

        $this->actingAs($user)->withCookie('cf_did', $deviceId)->get(route('books.index'));

        $this->travel(5)->minutes();
        $this->actingAs($user)->withCookie('cf_did', $deviceId)->get(route('books.index'));

        $device = UserDevice::query()->sole();
        $this->assertTrue($device->last_seen_at->equalTo($firstSeen), 'Second request inside the throttle window must not write.');

        $this->travel(6)->minutes();
        $this->actingAs($user)->withCookie('cf_did', $deviceId)->get(route('books.index'));

        $device->refresh();
        $this->assertTrue($device->last_seen_at->equalTo(now()), 'Request after the throttle window must refresh last_seen_at.');
    }

    public function test_malformed_fingerprint_is_stored_as_null(): void
    {
        $user = User::factory()->create();
        $deviceId = (string) Str::uuid();

        $this->actingAs($user)
            ->withCookie('cf_did', $deviceId)
            ->withUnencryptedCookie('cf_fp', '<script>alert(1)</script>')
            ->get(route('books.index'));

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'fingerprint' => null,
        ]);
    }

    public function test_invalid_device_cookie_is_replaced_with_a_fresh_uuid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withCookie('cf_did', 'not-a-uuid')
            ->get(route('books.index'));

        $device = UserDevice::query()->sole();
        $this->assertNotSame('not-a-uuid', $device->device_id);
        $this->assertTrue(Str::isUuid($device->device_id));
    }

    public function test_registration_itself_records_identity_for_the_new_user(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $deviceId = (string) Str::uuid();
        $fingerprint = str_repeat('cd', 16);

        $this->withCookie('cf_did', $deviceId)
            ->withUnencryptedCookie('cf_fp', $fingerprint)
            ->post(route('register.store'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'test@example.com')->sole();
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'fingerprint' => $fingerprint,
        ]);
        $this->assertDatabaseHas('user_ips', [
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ]);
    }
}
