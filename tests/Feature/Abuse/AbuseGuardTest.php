<?php

namespace Tests\Feature\Abuse;

use App\Models\BenefitGrant;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserIp;
use App\Services\Abuse\AbuseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AbuseGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): AbuseGuard
    {
        return app(AbuseGuard::class);
    }

    private function setCurrentRequestIp(string $ip): void
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]));
    }

    public function test_fresh_user_can_claim_and_reclaim_is_denied(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->guard()->canClaimBenefit($user, 'demo'));

        $grant = $this->guard()->claimBenefit($user, 'demo');

        $this->assertSame($user->id, $grant->user_id);
        $this->assertTrue($this->guard()->hasClaimed($user, 'demo'));
        $this->assertFalse($this->guard()->canClaimBenefit($user, 'demo'));
    }

    public function test_claiming_twice_returns_the_existing_grant(): void
    {
        $user = User::factory()->create();

        $first = $this->guard()->claimBenefit($user, 'demo');
        $second = $this->guard()->claimBenefit($user, 'demo');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('benefit_grants', 1);
    }

    public function test_device_sibling_is_denied(): void
    {
        $deviceId = (string) Str::uuid();

        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id, 'device_id' => $deviceId]);

        $sibling = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $sibling->id, 'device_id' => $deviceId]);

        $this->assertFalse($this->guard()->canClaimBenefit($sibling, 'demo'));
    }

    public function test_fingerprint_sibling_is_denied(): void
    {
        $fingerprint = str_repeat('ef', 16);

        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id, 'fingerprint' => $fingerprint]);

        $sibling = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $sibling->id, 'fingerprint' => $fingerprint]);

        $this->assertFalse($this->guard()->canClaimBenefit($sibling, 'demo'));
    }

    public function test_ip_sibling_is_denied_inside_the_window(): void
    {
        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id, 'ip' => '203.0.113.9']);

        $sibling = User::factory()->create();
        UserIp::factory()->create(['user_id' => $sibling->id, 'ip' => '203.0.113.9']);

        $this->assertFalse($this->guard()->canClaimBenefit($sibling, 'demo'));
    }

    public function test_ip_sibling_is_allowed_once_the_grant_is_older_than_the_window(): void
    {
        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id, 'ip' => '203.0.113.9']);

        $this->travel(31)->days();

        $sibling = User::factory()->create();
        UserIp::factory()->create(['user_id' => $sibling->id, 'ip' => '203.0.113.9']);

        $this->assertTrue($this->guard()->canClaimBenefit($sibling, 'demo'));
    }

    public function test_unrelated_user_is_allowed(): void
    {
        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id]);

        $stranger = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $stranger->id]);
        UserIp::factory()->create(['user_id' => $stranger->id]);

        $this->assertTrue($this->guard()->canClaimBenefit($stranger, 'demo'));
    }

    public function test_grants_for_other_benefits_do_not_interfere(): void
    {
        $deviceId = (string) Str::uuid();

        $claimer = User::factory()->create();
        BenefitGrant::factory()->create(['user_id' => $claimer->id, 'benefit' => 'other-perk', 'device_id' => $deviceId]);

        $sibling = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $sibling->id, 'device_id' => $deviceId]);

        $this->assertTrue($this->guard()->canClaimBenefit($sibling, 'demo'));
    }

    public function test_related_user_ids_clusters_by_device_fingerprint_and_recent_ip(): void
    {
        $deviceId = (string) Str::uuid();
        $fingerprint = str_repeat('aa', 16);

        $user = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $user->id, 'device_id' => $deviceId, 'fingerprint' => $fingerprint]);
        UserIp::factory()->create(['user_id' => $user->id, 'ip' => '198.51.100.7']);

        $deviceSibling = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $deviceSibling->id, 'device_id' => $deviceId]);

        $fingerprintSibling = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $fingerprintSibling->id, 'fingerprint' => $fingerprint]);

        $ipSibling = User::factory()->create();
        UserIp::factory()->create(['user_id' => $ipSibling->id, 'ip' => '198.51.100.7']);

        $staleIpSibling = User::factory()->create();
        UserIp::factory()->create([
            'user_id' => $staleIpSibling->id,
            'ip' => '198.51.100.7',
            'last_seen_at' => now()->subDays(40),
        ]);

        $stranger = User::factory()->create();
        UserDevice::factory()->create(['user_id' => $stranger->id]);

        $this->assertEqualsCanonicalizing(
            [$deviceSibling->id, $fingerprintSibling->id, $ipSibling->id],
            $this->guard()->relatedUserIds($user),
        );
    }

    public function test_free_benefit_is_not_offered_on_a_vpn_ip(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('abuse/vpn.txt', "10.9.0.0/16\n");

        $this->setCurrentRequestIp('10.9.9.9');

        $user = User::factory()->create();

        $this->assertTrue($this->guard()->canClaimBenefit($user, 'demo'));
        $this->assertFalse($this->guard()->canOfferFreeBenefit($user, 'demo'));
    }

    public function test_free_benefit_is_offered_when_ip_lists_are_missing(): void
    {
        Storage::fake('local');

        $this->setCurrentRequestIp('10.9.9.9');

        $user = User::factory()->create();

        $this->assertTrue($this->guard()->canOfferFreeBenefit($user, 'demo'));
    }

    public function test_free_benefit_is_offered_on_a_clean_ip(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('abuse/vpn.txt', "10.9.0.0/16\n");
        Storage::disk('local')->put('abuse/datacenter.txt', "192.0.2.0/24\n");

        $this->setCurrentRequestIp('84.12.34.56');

        $user = User::factory()->create();

        $this->assertTrue($this->guard()->canOfferFreeBenefit($user, 'demo'));
    }
}
