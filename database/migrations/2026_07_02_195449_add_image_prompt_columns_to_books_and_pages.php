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
            $table->text('hero_sheet_prompt')->nullable()->after('hero_sheet_path');
            $table->text('cover_prompt')->nullable()->after('cover_image_path');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->text('image_prompt')->nullable()->after('image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['hero_sheet_prompt', 'cover_prompt']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('image_prompt');
        });
    }
};
