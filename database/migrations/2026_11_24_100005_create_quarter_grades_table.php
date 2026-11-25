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
        Schema::create('quarter_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_quarter_id')->constrained('class_quarters')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            foreach (['ww', 'pt', 'qa'] as $component) {
                $table->decimal($component.'_percentage', 5, 2);
                $table->decimal($component.'_weighted', 5, 2);
            }
            $table->decimal('initial_grade', 5, 2);
            $table->unsignedTinyInteger('quarterly_grade');
            $table->unsignedTinyInteger('ww_weight');
            $table->unsignedTinyInteger('pt_weight');
            $table->unsignedTinyInteger('qa_weight');
            $table->timestamps();
            $table->unique(['class_quarter_id', 'student_id'], 'quarter_grades_quarter_student_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quarter_grades');
    }
};
