<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // literals, so the migration never depends on model code
    private const DEFAULT_WEIGHT_GROUPS = [
        'jhs_learning_area' => 'jhs_languages_ap_esp',
        'shs_core' => 'shs_core',
        'shs_applied' => 'shs_academic_other',
        'shs_specialized' => 'shs_academic_other',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('weight_group', 40)->nullable();
        });

        foreach (self::DEFAULT_WEIGHT_GROUPS as $type => $weightGroup) {
            DB::table('subjects')->where('type', $type)->update(['weight_group' => $weightGroup]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('weight_group');
        });
    }
};
