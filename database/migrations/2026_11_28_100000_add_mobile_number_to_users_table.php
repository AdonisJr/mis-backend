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
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile_number', 11)->nullable()->unique('users_mobile_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     * Drops the parent login keys.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_mobile_number_unique');
            $table->dropColumn('mobile_number');
        });
    }
};
