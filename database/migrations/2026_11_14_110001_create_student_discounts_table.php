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
        Schema::create('student_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('discount_type_id')->constrained()->restrictOnDelete();
            $table->string('reference_no', 50)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();
            // explicit name: the generated one exceeds MySQL's 64-character identifier limit; backstop for the once-per-type rule that the create controller re-checks under a student lock
            $table->unique(['student_id', 'school_year_id', 'discount_type_id'], 'student_discounts_assignment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_discounts');
    }
};
