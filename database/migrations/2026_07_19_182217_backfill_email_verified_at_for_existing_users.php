<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mark all pre-existing accounts as verified. Email verification is being
     * turned on in the same release; without this backfill, every customer who
     * registered before the change would be locked out of the routes behind
     * the "verified" middleware until they clicked a verification link they
     * never received.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    /**
     * Irreversible by design: we cannot know which rows were backfilled.
     */
    public function down(): void
    {
        //
    }
};
