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
        Schema::table('books', function (Blueprint $table) {
            $table->json('story_bible')->nullable()->after('hero_sheet_prompt');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->json('art_direction')->nullable()->after('image_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('story_bible');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('art_direction');
        });
    }
};
