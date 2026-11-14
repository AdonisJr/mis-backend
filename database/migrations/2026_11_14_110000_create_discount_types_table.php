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
        Schema::create('discount_types', function (Blueprint $table) {
            $table->id();
            // not unique: name uniqueness ignores case, so the discount type requests enforce it
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('source', 20);
            $table->string('value_type', 20);
            $table->decimal('value', 12, 2);
            $table->string('base', 20);
            $table->json('stages');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_types');
    }
};
