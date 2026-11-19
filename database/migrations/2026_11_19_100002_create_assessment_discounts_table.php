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
        Schema::create('assessment_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('discount_type_name', 100);
            $table->string('source', 20);
            $table->string('value_type', 20);
            $table->decimal('value', 12, 2);
            $table->string('base', 20);
            $table->string('reference_no', 50)->nullable();
            $table->decimal('computed_amount', 14, 2);
            $table->decimal('applied_amount', 14, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_discounts');
    }
};
