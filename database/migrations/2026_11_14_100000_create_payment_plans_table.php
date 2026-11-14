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
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            // not unique: name uniqueness ignores case, so the payment plan requests and controllers enforce it
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('down_payment_type', 20)->nullable();
            $table->decimal('down_payment_value', 12, 2)->nullable();
            $table->string('adjustment_kind', 20)->nullable();
            $table->string('adjustment_type', 20)->nullable();
            $table->decimal('adjustment_value', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
