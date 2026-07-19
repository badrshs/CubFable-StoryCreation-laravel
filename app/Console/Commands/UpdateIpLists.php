<?php

namespace App\Console\Commands;

use App\Services\Abuse\IpIntelligence;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

#[Signature('abuse:update-ip-lists')]
#[Description('Download the free VPN and datacenter CIDR lists used to flag IPs; existing lists are kept when a download fails validation')]
class UpdateIpLists extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lists = [
            'vpn.txt' => (string) config('cubfable.abuse.ip_lists.vpn_url'),
            'datacenter.txt' => (string) config('cubfable.abuse.ip_lists.datacenter_url'),
        ];

        $failures = 0;

        foreach ($lists as $file => $url) {
            try {
                $response = Http::timeout(60)->get($url);
            } catch (ConnectionException $exception) {
                $this->error("{$file}: download failed ({$exception->getMessage()}); kept the existing list.");
                $failures++;

                continue;
            }

            if (! $response->successful() || ! $this->looksLikeCidrList($response->body())) {
                $this->error("{$file}: response was not a CIDR list; kept the existing list.");
                $failures++;

                continue;
            }

            Storage::disk('local')->put(IpIntelligence::listPath($file), $response->body());
            $lines = substr_count(trim($response->body()), "\n") + 1;
            $this->info("{$file}: updated ({$lines} ranges).");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A sane list is non-trivial and its first lines parse as IPv4 CIDR
     * ranges or plain IPv4 addresses, so an HTML error page never replaces
     * a good list.
     */
    private function looksLikeCidrList(string $body): bool
    {
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\r?\n/', $body) ?: []),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        if (count($lines) < 100) {
            return false;
        }

        foreach (array_slice($lines, 0, 25) as $line) {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?$/', $line) !== 1) {
                return false;
            }
        }

        return true;
    }
}
