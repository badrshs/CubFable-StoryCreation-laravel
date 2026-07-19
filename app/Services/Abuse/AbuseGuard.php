<?php

namespace App\Services\Abuse;

use App\Models\BenefitGrant;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserIp;
use App\Support\ClientIp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Decides whether a user may receive a free benefit (e.g. the demo book).
 * Accounts sharing a device cookie, browser fingerprint, or recent IP are
 * treated as one household: the first account to claim a benefit consumes it
 * for the whole cluster. Soft-gate only; callers route ineligible users to
 * the payment page instead of showing an error.
 */
class AbuseGuard
{
    public function __construct(public IpIntelligence $ipIntelligence) {}

    /**
     * Other user ids sharing a device, fingerprint, or recent IP with this user.
     *
     * @return list<int>
     */
    public function relatedUserIds(User $user): array
    {
        [$deviceIds, $fingerprints] = $this->deviceIdentifiers($user);
        $ips = $user->ipRecords()->pluck('ip')->all();

        $related = [];

        if ($deviceIds !== [] || $fingerprints !== []) {
            $related = UserDevice::query()
                ->where('user_id', '!=', $user->id)
                ->where(function (Builder $query) use ($deviceIds, $fingerprints) {
                    if ($deviceIds !== []) {
                        $query->orWhereIn('device_id', $deviceIds);
                    }
                    if ($fingerprints !== []) {
                        $query->orWhereIn('fingerprint', $fingerprints);
                    }
                })
                ->pluck('user_id')
                ->all();
        }

        if ($ips !== []) {
            $related = array_merge($related, UserIp::query()
                ->where('user_id', '!=', $user->id)
                ->whereIn('ip', $ips)
                ->where('last_seen_at', '>=', now()->subDays($this->ipWindowDays()))
                ->pluck('user_id')
                ->all());
        }

        return array_values(array_unique(array_map(intval(...), $related)));
    }

    public function hasClaimed(User $user, string $benefit): bool
    {
        return $user->benefitGrants()->where('benefit', $benefit)->exists();
    }

    /**
     * True when neither the user nor any account in their device/IP cluster
     * has already claimed the benefit.
     */
    public function canClaimBenefit(User $user, string $benefit): bool
    {
        if ($this->hasClaimed($user, $benefit)) {
            return false;
        }

        [$deviceIds, $fingerprints] = $this->deviceIdentifiers($user);
        $ips = $user->ipRecords()->pluck('ip')->all();

        if (($currentDevice = $this->currentDeviceId()) !== null) {
            $deviceIds[] = $currentDevice;
        }
        if (($currentFingerprint = $this->currentFingerprint()) !== null) {
            $fingerprints[] = $currentFingerprint;
        }
        if (($currentIp = ClientIp::from(request())) !== null) {
            $ips[] = $currentIp;
        }

        if ($deviceIds === [] && $fingerprints === [] && $ips === []) {
            return true;
        }

        return ! BenefitGrant::query()
            ->where('benefit', $benefit)
            ->where('user_id', '!=', $user->id)
            ->where(function (Builder $query) use ($deviceIds, $fingerprints, $ips) {
                if ($deviceIds !== []) {
                    $query->orWhereIn('device_id', $deviceIds);
                }
                if ($fingerprints !== []) {
                    $query->orWhereIn('fingerprint', $fingerprints);
                }
                if ($ips !== []) {
                    $query->orWhere(function (Builder $ipQuery) use ($ips) {
                        $ipQuery
                            ->whereIn('ip', $ips)
                            ->where('created_at', '>=', now()->subDays($this->ipWindowDays()));
                    });
                }
            })
            ->exists();
    }

    /**
     * The one question the demo asks: show the free offer, or send the user
     * straight to the payment page? Denies when the cluster already claimed
     * the benefit or the current request comes from a VPN/datacenter IP.
     * Unknown IP reputation (lists not downloaded) fails open.
     */
    public function canOfferFreeBenefit(User $user, string $benefit): bool
    {
        if (! $this->canClaimBenefit($user, $benefit)) {
            return false;
        }

        $ip = ClientIp::from(request());

        if ($ip === null) {
            return true;
        }

        return $this->ipIntelligence->isVpn($ip) !== true
            && $this->ipIntelligence->isDatacenter($ip) !== true;
    }

    /**
     * Record the benefit as consumed, snapshotting the identifiers of the
     * request that claimed it. Race-safe: concurrent claims collapse onto
     * the unique (user, benefit) row.
     */
    public function claimBenefit(User $user, string $benefit): BenefitGrant
    {
        try {
            return BenefitGrant::create([
                'user_id' => $user->id,
                'benefit' => $benefit,
                'device_id' => $this->currentDeviceId(),
                'fingerprint' => $this->currentFingerprint(),
                'ip' => ClientIp::from(request()),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $user->benefitGrants()->where('benefit', $benefit)->firstOrFail();
        }
    }

    /**
     * Fingerprints are client-controlled data; accept only hex ids in the
     * shape FingerprintJS produces, anything else counts as absent.
     */
    public static function sanitizeFingerprint(?string $fingerprint): ?string
    {
        if ($fingerprint === null || preg_match('/^[a-f0-9]{16,64}$/i', $fingerprint) !== 1) {
            return null;
        }

        return strtolower($fingerprint);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} device ids and fingerprints
     */
    private function deviceIdentifiers(User $user): array
    {
        $deviceIds = [];
        $fingerprints = [];

        foreach ($user->devices()->get(['device_id', 'fingerprint']) as $device) {
            $deviceIds[] = $device->device_id;

            if ($device->fingerprint !== null) {
                $fingerprints[] = $device->fingerprint;
            }
        }

        return [$deviceIds, array_values(array_unique($fingerprints))];
    }

    private function currentDeviceId(): ?string
    {
        $deviceId = request()->cookie((string) config('cubfable.abuse.device_cookie'));

        return is_string($deviceId) && Str::isUuid($deviceId) ? $deviceId : null;
    }

    private function currentFingerprint(): ?string
    {
        $fingerprint = request()->cookie((string) config('cubfable.abuse.fingerprint_cookie'));

        return self::sanitizeFingerprint(is_string($fingerprint) ? $fingerprint : null);
    }

    private function ipWindowDays(): int
    {
        return (int) config('cubfable.abuse.ip_window_days');
    }
}
