<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserIp;
use App\Services\Abuse\AbuseGuard;
use App\Services\Abuse\IpIntelligence;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gives every browser a long-lived device id cookie and, for authenticated
 * requests, records which devices, fingerprints, and IPs each account uses.
 * This identity ledger is what AbuseGuard clusters accounts by when handing
 * out free benefits. Recording is best-effort and throttled; it must never
 * break a request.
 */
class RecordDeviceIdentity
{
    public function __construct(public IpIntelligence $ipIntelligence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->cookie($this->deviceCookieName());

        if (! is_string($deviceId) || ! Str::isUuid($deviceId)) {
            $deviceId = (string) Str::uuid();
        }

        // Re-queued on every response so the expiry keeps sliding forward;
        // browsers cap cookie lifetimes (Chrome at ~400 days), so a single
        // five-year stamp would silently be shortened.
        Cookie::queue(cookie(
            name: $this->deviceCookieName(),
            value: $deviceId,
            minutes: 5 * 365 * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));

        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            try {
                $this->record($user, $deviceId, $request);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }

    private function record(User $user, string $deviceId, Request $request): void
    {
        $throttleMinutes = (int) config('cubfable.abuse.record_throttle_minutes');

        if (! Cache::add("device-identity:{$user->id}:{$deviceId}", true, now()->addMinutes($throttleMinutes))) {
            return;
        }

        $now = now();

        $rawFingerprint = $request->cookie((string) config('cubfable.abuse.fingerprint_cookie'));
        $fingerprint = AbuseGuard::sanitizeFingerprint(is_string($rawFingerprint) ? $rawFingerprint : null);
        $userAgent = $request->userAgent();
        $userAgent = $userAgent !== null ? substr($userAgent, 0, 512) : null;

        $device = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        if ($device === null) {
            try {
                UserDevice::create([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'fingerprint' => $fingerprint,
                    'user_agent' => $userAgent,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request already created the row.
            }
        } else {
            $updates = ['user_agent' => $userAgent, 'last_seen_at' => $now];

            if ($fingerprint !== null) {
                $updates['fingerprint'] = $fingerprint;
            }

            $device->update($updates);
        }

        $ip = $request->ip();

        if ($ip === null) {
            return;
        }

        $ipRecord = UserIp::query()
            ->where('user_id', $user->id)
            ->where('ip', $ip)
            ->first();

        if ($ipRecord === null) {
            try {
                UserIp::create([
                    'user_id' => $user->id,
                    'ip' => $ip,
                    'is_vpn' => $this->ipIntelligence->isVpn($ip),
                    'is_datacenter' => $this->ipIntelligence->isDatacenter($ip),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request already created the row.
            }
        } else {
            $updates = ['last_seen_at' => $now];

            // Flags stay null until the CIDR lists are downloaded; keep
            // retrying so old rows get classified once the lists arrive.
            if ($ipRecord->is_vpn === null) {
                $updates['is_vpn'] = $this->ipIntelligence->isVpn($ip);
            }
            if ($ipRecord->is_datacenter === null) {
                $updates['is_datacenter'] = $this->ipIntelligence->isDatacenter($ip);
            }

            $ipRecord->update($updates);
        }
    }

    /**
     * @return non-empty-string
     */
    private function deviceCookieName(): string
    {
        $name = (string) config('cubfable.abuse.device_cookie');

        return $name !== '' ? $name : 'cf_did';
    }
}
