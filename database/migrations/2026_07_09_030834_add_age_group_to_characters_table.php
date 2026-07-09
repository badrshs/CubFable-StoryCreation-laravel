<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a character is an adult or a child: companions are often
     * parents/grandparents, and the prompts must say so or the engines
     * render everyone at the book's child age range. Null (legacy rows)
     * behaves like 'child' - the pre-existing behavior.
     */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('age_group', 10)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('age_group');
        });
    }
};
