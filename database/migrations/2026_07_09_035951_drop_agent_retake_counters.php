<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The retake counters existed only for the removed claude-agent
     * pipeline; nothing reads or writes them anymore.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['cover_retake_count', 'hero_sheet_retake_count']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('retake_count');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedTinyInteger('cover_retake_count')->default(0)->after('story_bible');
            $table->unsignedTinyInteger('hero_sheet_retake_count')->default(0)->after('cover_retake_count');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->unsignedTinyInteger('retake_count')->default(0)->after('art_direction');
        });
    }
};
