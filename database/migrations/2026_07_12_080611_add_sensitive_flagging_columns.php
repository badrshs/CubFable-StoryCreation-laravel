<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content-flag review support: every image attempt records which engine
     * ran it and why it failed, and pages/covers that exhausted the fallback
     * chain carry a flagged_at marker for the admin moderation queue.
     */
    public function up(): void
    {
        Schema::table('image_prompts', function (Blueprint $table): void {
            $table->string('provider', 40)->nullable()->after('variant');
            $table->string('model', 200)->nullable()->after('provider');
            $table->unsignedTinyInteger('round')->default(1)->after('attempt');
            $table->text('error')->nullable()->after('accepted');
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->timestamp('flagged_at')->nullable()->after('status');
        });

        Schema::table('books', function (Blueprint $table): void {
            $table->timestamp('cover_flagged_at')->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('image_prompts', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'model', 'round', 'error']);
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('flagged_at');
        });

        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn('cover_flagged_at');
        });
    }
};
