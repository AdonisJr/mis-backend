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
        Schema::create('grading_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('component', 2);
            $table->string('title', 150);
            $table->unsignedSmallInteger('highest_possible_score');
            $table->date('activity_date')->nullable();
            $table->timestamps();
            $table->index(['class_id', 'quarter'], 'grading_activities_class_quarter_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_activities');
    }
};
