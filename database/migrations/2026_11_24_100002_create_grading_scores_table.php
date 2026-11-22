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
        Schema::create('grading_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_activity_id')->constrained('grading_activities')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->decimal('score', 4, 1)->nullable();
            $table->timestamps();
            $table->unique(['grading_activity_id', 'student_id'], 'grading_scores_activity_student_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_scores');
    }
};
