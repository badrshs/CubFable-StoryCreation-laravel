<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marks_unverified_users_as_verified_at_their_creation_time(): void
    {
        $this->freezeTime();

        $unverified = User::factory()->unverified()->create();
        $verifiedAt = now()->subDays(3)->startOfSecond();
        $verified = User::factory()->create(['email_verified_at' => $verifiedAt]);

        $migration = include database_path('migrations/2026_07_19_182217_backfill_email_verified_at_for_existing_users.php');
        $migration->up();

        $this->assertTrue($unverified->fresh()->email_verified_at->equalTo($unverified->fresh()->created_at));
        $this->assertTrue($verified->fresh()->email_verified_at->equalTo($verifiedAt));
    }
}
