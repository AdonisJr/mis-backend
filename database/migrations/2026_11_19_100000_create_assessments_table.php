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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('current');
            $table->string('plan_name', 100);
            $table->string('adjustment_kind', 20)->nullable();
            $table->string('adjustment_type', 20)->nullable();
            $table->decimal('adjustment_value', 12, 2)->nullable();
            $table->decimal('adjustment_amount', 14, 2)->default(0);
            $table->decimal('gross_tuition', 14, 2);
            $table->decimal('gross_miscellaneous', 14, 2);
            $table->decimal('gross_other', 14, 2);
            $table->decimal('gross_total', 14, 2);
            $table->decimal('discount_tuition', 14, 2);
            $table->decimal('discount_miscellaneous', 14, 2);
            $table->decimal('discount_other', 14, 2);
            $table->decimal('discount_total', 14, 2);
            $table->decimal('net_total', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            // at most one current row per student and year is checked by the request and re-checked under the student lock
            $table->index(['student_id', 'school_year_id', 'status']);
            $table->index(['school_year_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
