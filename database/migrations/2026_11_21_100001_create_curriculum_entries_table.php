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
        Schema::create('curriculum_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('term_number')->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            // not unique: a unique index treats NULL program_id/term_number as distinct, so the requests and the locked re-check enforce the key rule
            // explicit name: the generated one is 77 chars, over MySQL's 64-char identifier limit
            $table->index(['school_year_id', 'grade_level_id', 'program_id', 'term_number'], 'curriculum_entries_group_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_entries');
    }
};
