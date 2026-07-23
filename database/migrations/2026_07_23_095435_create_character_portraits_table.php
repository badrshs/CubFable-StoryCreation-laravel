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
        Schema::create('character_portraits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('art_style', 40);
            $table->string('path');
            $table->text('prompt')->nullable();
            $table->string('engine_provider', 40)->nullable();
            $table->string('engine_model', 200)->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'art_style']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_portraits');
    }
};
