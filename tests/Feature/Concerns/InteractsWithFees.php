<?php

namespace Tests\Feature\Concerns;

use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\FeeScheduleLine;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\User;

trait InteractsWithFees
{
    private const FEE_ITEMS_URL = '/api/mis/fee-items';

    private const FEE_SCHEDULES_URL = '/api/mis/fee-schedules';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2031 = ['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const ABM_PROGRAM = ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    private const GRADE_7 = 'Grade 7';

    private const GRADE_8 = 'Grade 8';

    private const GRADE_11 = 'Grade 11';

    private const GRADE_12 = 'Grade 12';

    private const FIRST_YEAR_COLLEGE = '1st Year College';

    private const TUITION_FEE = 'Tuition Fee';

    private const LIBRARY_FEE = 'Library Fee';

    private const LABORATORY_FEE = 'Laboratory Fee';

    private const ID_CARD = 'ID Card';

    private SchoolYear $schoolYear;

    private string $adminToken;

    private function setUpFeeFixtures(): void
    {
        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->adminToken = $this->createAdminToken();
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor(User::factory()->admin()->create(['email' => 'acting@admin.test']));
    }

    private function createRegistrarToken(): string
    {
        return $this->createTokenFor(User::factory()->registrar()->create(['email' => 'registrar@school.test']));
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    private function findGradeLevelId(string $gradeLevelName): int
    {
        return GradeLevel::where('name', $gradeLevelName)->value('id');
    }

    /**
     * @return array{school_year_id: int, grade_level_id: int, program_id: int|null}
     */
    private function buildKeys(string $gradeLevelName, ?int $programId = null, ?SchoolYear $schoolYear = null): array
    {
        return [
            'school_year_id' => ($schoolYear ?? $this->schoolYear)->id,
            'grade_level_id' => $this->findGradeLevelId($gradeLevelName),
            'program_id' => $programId,
        ];
    }

    private function createFeeItem(string $name, string $category = FeeItem::CATEGORY_MISCELLANEOUS): FeeItem
    {
        return FeeItem::factory()->create(['name' => $name, 'category' => $category]);
    }

    /**
     * @param  list<array{0: FeeItem, 1: string}>  $lineSpecs
     * @return list<array{fee_item_id: int, amount: string}>
     */
    private function buildLines(array $lineSpecs): array
    {
        return array_map(fn (array $lineSpec) => ['fee_item_id' => $lineSpec[0]->id, 'amount' => $lineSpec[1]], $lineSpecs);
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  list<array{0: FeeItem, 1: string}>  $lineSpecs
     */
    private function createSchedule(array $keys, array $lineSpecs = []): FeeSchedule
    {
        $feeSchedule = FeeSchedule::factory()->create($keys);

        foreach ($lineSpecs ?: [[FeeItem::factory()->create(), '1000.00']] as $lineSpec) {
            FeeScheduleLine::factory()->create(['fee_schedule_id' => $feeSchedule->id, 'fee_item_id' => $lineSpec[0]->id, 'amount' => $lineSpec[1]]);
        }

        return $feeSchedule;
    }

    private function createProgram(array $programFields): Program
    {
        return Program::factory()->create($programFields);
    }
}
