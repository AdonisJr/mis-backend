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
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->date('enrolled_at');
            $table->date('ended_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            // not unique: "one enrolled row per period" cannot be expressed portably when term_id is NULL, so EnrollmentController enforces it under row locks
            $table->index(['section_id', 'term_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
