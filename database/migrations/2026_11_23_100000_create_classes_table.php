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
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->foreignId('curriculum_entry_id')->constrained('curriculum_entries')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $table->unsignedTinyInteger('term_number')->nullable();
            $table->timestamps();
            $table->index(['school_year_id', 'term_number'], 'classes_period_index');
            // not unique: one class per entry is enforced by the request and the locked re-check, like curriculum_entries
            $table->index(['section_id', 'curriculum_entry_id'], 'classes_section_entry_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
