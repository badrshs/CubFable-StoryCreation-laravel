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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            $table->text('description');
            $table->string('theme');
            $table->unsignedInteger('age_min');
            $table->unsignedInteger('age_max');
            $table->text('cover_image_url');
            $table->unsignedInteger('page_count');
            $table->json('life_lessons');
            $table->json('art_styles');
            $table->json('subjects');
            $table->json('fonts');
            $table->text('image_prompt')->default('');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
