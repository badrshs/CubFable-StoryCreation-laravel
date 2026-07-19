<?php

namespace App\Services\Abuse;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Flags IPs that belong to VPN egress or datacenter ranges, using the free
 * CIDR lists downloaded by abuse:update-ip-lists. Purely informational: a
 * missing list yields null ("unknown"), never an error, so the app degrades
 * gracefully when the lists were never fetched.
 */
class IpIntelligence
{
    private const VPN_LIST = 'vpn.txt';

    private const DATACENTER_LIST = 'datacenter.txt';

    /** @var array<string, list<string>|null> */
    private array $ranges = [];

    public function isVpn(string $ip): ?bool
    {
        return $this->matches($ip, self::VPN_LIST);
    }

    public function isDatacenter(string $ip): ?bool
    {
        return $this->matches($ip, self::DATACENTER_LIST);
    }

    public static function listPath(string $list): string
    {
        return config('cubfable.abuse.ip_lists.path').'/'.$list;
    }

    private function matches(string $ip, string $list): ?bool
    {
        $ranges = $this->rangesFor($list);

        if ($ranges === null) {
            return null;
        }

        /** @var bool $verdict */
        $verdict = Cache::remember(
            "ip-intel:{$list}:{$ip}",
            now()->addDay(),
            fn (): bool => IpUtils::checkIp($ip, $ranges),
        );

        return $verdict;
    }

    /**
     * @return list<string>|null null when the list file has not been downloaded
     */
    private function rangesFor(string $list): ?array
    {
        if (array_key_exists($list, $this->ranges)) {
            return $this->ranges[$list];
        }

        $contents = Storage::disk('local')->get(self::listPath($list));

        if ($contents === null) {
            return $this->ranges[$list] = null;
        }

        $ranges = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                $ranges[] = $line;
            }
        }

        return $this->ranges[$list] = $ranges;
    }
}
