<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp every image version with the engine that produced it, so model
     * experiments stay attributable. Versions from before this feature keep
     * nulls.
     */
    public function up(): void
    {
        Schema::table('image_versions', function (Blueprint $table) {
            $table->string('engine_provider', 40)->nullable()->after('prompt');
            $table->string('engine_model', 120)->nullable()->after('engine_provider');
        });
    }

    public function down(): void
    {
        Schema::table('image_versions', function (Blueprint $table) {
            $table->dropColumn(['engine_provider', 'engine_model']);
        });
    }
};
