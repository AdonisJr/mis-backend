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
        Schema::create('class_quarters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('status', 20)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['class_id', 'quarter'], 'class_quarters_class_quarter_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_quarters');
    }
};
