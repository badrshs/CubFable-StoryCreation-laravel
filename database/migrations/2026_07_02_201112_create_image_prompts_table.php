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
        Schema::create('image_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->unsignedTinyInteger('attempt');
            $table->string('variant');
            $table->text('prompt');
            $table->boolean('accepted')->default(false);
            $table->timestamps();
            $table->index(['book_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_prompts');
    }
};
