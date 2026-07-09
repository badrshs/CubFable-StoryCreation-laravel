<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every generated image, forever: the book/page pointer columns mark
     * which version is active, and older files stay on disk so the admin
     * can restore any of them.
     */
    public function up(): void
    {
        Schema::create('image_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            // A restart deletes and recreates page rows, so versions keep the
            // page NUMBER as their durable anchor and only null the page id.
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('slot', 20); // cover | sheet | page
            $table->string('path', 500);
            $table->text('prompt')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_versions');
    }
};
