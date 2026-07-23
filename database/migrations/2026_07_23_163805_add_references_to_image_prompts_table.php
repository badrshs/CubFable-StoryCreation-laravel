<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('image_prompts', function (Blueprint $table) {
            // The reference images actually sent with this attempt (their
            // stored paths + labels), so an admin can see whether a character
            // travelled as a stylized portrait or as their raw photo.
            $table->json('references')->nullable()->after('prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_prompts', function (Blueprint $table) {
            $table->dropColumn('references');
        });
    }
};
