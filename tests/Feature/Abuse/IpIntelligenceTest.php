<?php

namespace Tests\Feature\Abuse;

use App\Services\Abuse\IpIntelligence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IpIntelligenceTest extends TestCase
{
    public function test_matches_ips_against_the_downloaded_lists(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('abuse/vpn.txt', "# comment line\n10.0.0.0/8\n192.168.1.0/24\n");
        Storage::disk('local')->put('abuse/datacenter.txt', "203.0.113.0/24\n");

        $intelligence = app(IpIntelligence::class);

        $this->assertTrue($intelligence->isVpn('10.1.2.3'));
        $this->assertTrue($intelligence->isVpn('192.168.1.44'));
        $this->assertFalse($intelligence->isVpn('8.8.8.8'));
        $this->assertTrue($intelligence->isDatacenter('203.0.113.77'));
        $this->assertFalse($intelligence->isDatacenter('8.8.8.8'));
    }

    public function test_missing_list_yields_unknown(): void
    {
        Storage::fake('local');

        $intelligence = app(IpIntelligence::class);

        $this->assertNull($intelligence->isVpn('10.1.2.3'));
        $this->assertNull($intelligence->isDatacenter('10.1.2.3'));
    }

    public function test_update_command_stores_valid_lists(): void
    {
        Storage::fake('local');

        $list = collect(range(1, 120))->map(fn (int $i): string => "10.0.{$i}.0/24")->implode("\n");
        Http::fake(['raw.githubusercontent.com/*' => Http::response($list)]);

        $this->artisan('abuse:update-ip-lists')->assertExitCode(0);

        Storage::disk('local')->assertExists('abuse/vpn.txt');
        Storage::disk('local')->assertExists('abuse/datacenter.txt');
        $this->assertSame($list, Storage::disk('local')->get('abuse/vpn.txt'));
    }

    public function test_update_command_keeps_the_existing_list_when_the_download_is_not_a_cidr_list(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('abuse/vpn.txt', "10.0.0.0/8\n");
        Storage::disk('local')->put('abuse/datacenter.txt', "203.0.113.0/24\n");

        Http::fake(['raw.githubusercontent.com/*' => Http::response('<html>rate limited</html>')]);

        $this->artisan('abuse:update-ip-lists')->assertExitCode(1);

        $this->assertSame("10.0.0.0/8\n", Storage::disk('local')->get('abuse/vpn.txt'));
        $this->assertSame("203.0.113.0/24\n", Storage::disk('local')->get('abuse/datacenter.txt'));
    }

    public function test_update_command_rejects_a_suspiciously_short_list(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('abuse/vpn.txt', "10.0.0.0/8\n");

        Http::fake(['raw.githubusercontent.com/*' => Http::response("10.0.0.0/8\n10.0.1.0/24\n")]);

        $this->artisan('abuse:update-ip-lists')->assertExitCode(1);

        $this->assertSame("10.0.0.0/8\n", Storage::disk('local')->get('abuse/vpn.txt'));
    }
}
