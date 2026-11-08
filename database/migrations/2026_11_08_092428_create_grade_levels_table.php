<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // literal copies, not the Student grade-level list or the GradeLevel stage constants: this migration must insert the same rows even if those constants change later
    private const GRADE_LEVEL_ROWS = [
        ['name' => 'Grade 7', 'sort_order' => 1, 'stage' => 'jhs'],
        ['name' => 'Grade 8', 'sort_order' => 2, 'stage' => 'jhs'],
        ['name' => 'Grade 9', 'sort_order' => 3, 'stage' => 'jhs'],
        ['name' => 'Grade 10', 'sort_order' => 4, 'stage' => 'jhs'],
        ['name' => 'Grade 11', 'sort_order' => 5, 'stage' => 'shs'],
        ['name' => 'Grade 12', 'sort_order' => 6, 'stage' => 'shs'],
        ['name' => '1st Year College', 'sort_order' => 7, 'stage' => 'college'],
        ['name' => '2nd Year College', 'sort_order' => 8, 'stage' => 'college'],
        ['name' => '3rd Year College', 'sort_order' => 9, 'stage' => 'college'],
        ['name' => '4th Year College', 'sort_order' => 10, 'stage' => 'college'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->string('stage', 10);
            $table->timestamps();
        });

        $this->insertGradeLevels();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_levels');
    }

    private function insertGradeLevels(): void
    {
        $insertedAt = now();

        DB::table('grade_levels')->insert(array_map(fn (array $gradeLevelRow) => [...$gradeLevelRow, 'created_at' => $insertedAt, 'updated_at' => $insertedAt], self::GRADE_LEVEL_ROWS));
    }
};
