<?php

namespace Tests\Feature;

use App\Http\Controllers\SectionController;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SectionCrudTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const DEFAULT_CAPACITY = 40;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    private const GRADE_7 = 'Grade 7';

    private const GRADE_8 = 'Grade 8';

    private const GRADE_9 = 'Grade 9';

    private const GRADE_10 = 'Grade 10';

    private const GRADE_11 = 'Grade 11';

    private const FIRST_YEAR_COLLEGE = '1st Year College';

    private const SECTION_KEYS = ['id', 'school_year_id', 'grade_level_id', 'program_id', 'name', 'capacity', 'room', 'created_at', 'updated_at', 'grade_level', 'program', 'enrolled_count'];

    private const SECTION_COLUMNS = ['id', 'school_year_id', 'grade_level_id', 'program_id', 'name', 'capacity', 'room', 'created_at', 'updated_at'];

    private SchoolYear $schoolYear;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->adminToken = $this->createAdminToken();
    }

    public function test_sections_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('sections'));
        $this->assertTrue(Schema::hasColumns('sections', self::SECTION_COLUMNS));

        $sectionColumns = collect(Schema::getColumns('sections'))->keyBy('name');
        foreach (['school_year_id', 'grade_level_id', 'name', 'capacity'] as $requiredColumn) {
            $this->assertFalse($sectionColumns[$requiredColumn]['nullable'], $requiredColumn.' must not be nullable');
        }
        $this->assertTrue($sectionColumns['program_id']['nullable']);
        $this->assertTrue($sectionColumns['room']['nullable']);
    }

    public function test_foreign_keys_restrict_deletes(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('sections'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();

        $this->assertEquals([
            'school_year_id' => ['school_years', 'restrict'],
            'grade_level_id' => ['grade_levels', 'restrict'],
            'program_id' => ['programs', 'restrict'],
        ], $foreignKeys);

        $section = Section::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('school_years')->where('id', $section->school_year_id)->delete();
    }

    public function test_admin_creates_jhs_section(): void
    {
        $gradeSevenId = $this->findGradeLevelId(self::GRADE_7);

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A']))
            ->assertCreated()
            ->assertJsonPath('section.name', 'A')
            ->assertJsonPath('section.program_id', null)
            ->assertJsonPath('section.program', null)
            ->assertJsonPath('section.capacity', self::DEFAULT_CAPACITY)
            ->assertJsonPath('section.room', null)
            ->assertJsonPath('section.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('section.grade_level', ['id' => $gradeSevenId, 'name' => self::GRADE_7, 'stage' => GradeLevel::STAGE_JHS]);

        $this->assertSame(self::SECTION_KEYS, array_keys($createResponse->json('section')));
        $this->assertDatabaseHas('sections', ['name' => 'A', 'program_id' => null]);
    }

    public function test_admin_creates_shs_section_with_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-A', 'room' => 'Rm 101']))
            ->assertCreated()
            ->assertJsonPath('section.program', ['id' => $stemProgram->id, 'code' => 'STEM', 'name' => self::STEM_PROGRAM['name']])
            ->assertJsonPath('section.grade_level.stage', GradeLevel::STAGE_SHS)
            ->assertJsonPath('section.room', 'Rm 101');
    }

    public function test_admin_creates_college_section_with_program(): void
    {
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::FIRST_YEAR_COLLEGE, ['program_id' => $bsitProgram->id, 'name' => 'COL-OK']))
            ->assertCreated()
            ->assertJsonPath('section.program.code', 'BSIT')
            ->assertJsonPath('section.grade_level.stage', GradeLevel::STAGE_COLLEGE);
    }

    public function test_create_accepts_minimum_capacity(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_8, ['name' => 'Cap-1', 'capacity' => 1]))
            ->assertCreated()
            ->assertJsonPath('section.capacity', 1);
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_8, ['name' => 'Cap-40', 'capacity' => 40]))
            ->assertCreated()
            ->assertJsonPath('section.capacity', 40);

        $this->assertSame(1, Section::where('name', 'Cap-1')->value('capacity'));
    }

    public function test_create_accepts_maximum_capacity(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_8, ['name' => 'A', 'capacity' => StoreSectionRequest::CAPACITY_MAX]))
            ->assertCreated()
            ->assertJsonPath('section.capacity', StoreSectionRequest::CAPACITY_MAX);
    }

    public function test_create_stores_room_or_null(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, Arr::except($this->buildSectionPayload(self::GRADE_9, ['name' => 'NoRoom']), 'room'))
            ->assertCreated()
            ->assertJsonPath('section.room', null);
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_9, ['name' => 'WithRoom', 'room' => 'Rm 101']))
            ->assertCreated()
            ->assertJsonPath('section.room', 'Rm 101');
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_9, ['name' => 'EmptyRoom', 'room' => '']))
            ->assertCreated()
            ->assertJsonPath('section.room', null);
    }

    public function test_create_trims_name_and_room(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => '  Rizal  ', 'room' => ' Rm 101 ']))
            ->assertCreated()
            ->assertJsonPath('section.name', 'Rizal')
            ->assertJsonPath('section.room', 'Rm 101');

        $this->assertDatabaseHas('sections', ['name' => 'Rizal', 'room' => 'Rm 101']);
    }

    public function test_create_accepts_numeric_strings_for_ids_and_capacity(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $gradeElevenId = $this->findGradeLevelId(self::GRADE_11);

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_11, [
                'school_year_id' => (string) $this->schoolYear->id,
                'grade_level_id' => (string) $gradeElevenId,
                'program_id' => (string) $stemProgram->id,
                'capacity' => '40',
                'name' => 'Rizal',
            ]))
            ->assertCreated();

        $this->assertSame($this->schoolYear->id, $createResponse->json('section.school_year_id'));
        $this->assertSame($gradeElevenId, $createResponse->json('section.grade_level_id'));
        $this->assertSame($stemProgram->id, $createResponse->json('section.program_id'));
        $this->assertSame(40, $createResponse->json('section.capacity'));
    }

    public function test_create_requires_fields(): void
    {
        $validPayload = $this->buildSectionPayload(self::GRADE_7, ['name' => 'No-Field']);

        foreach (['school_year_id', 'grade_level_id', 'name', 'capacity'] as $requiredField) {
            $this->withToken($this->adminToken)
                ->postJson(self::SECTIONS_URL, Arr::except($validPayload, $requiredField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
            $this->withToken($this->adminToken)
                ->postJson(self::SECTIONS_URL, [...$validPayload, $requiredField => ''])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
        }

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_create_rejects_invalid_capacity(): void
    {
        $validPayload = $this->buildSectionPayload(self::GRADE_7, ['name' => 'Bad-Cap']);

        foreach ([0, -5, 1.5, 'abc', '1.0', true, [40], StoreSectionRequest::CAPACITY_MAX + 1] as $invalidCapacity) {
            $this->withToken($this->adminToken)
                ->postJson(self::SECTIONS_URL, [...$validPayload, 'capacity' => $invalidCapacity])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['capacity']);
        }

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_create_rejects_unknown_or_malformed_ids(): void
    {
        $jhsPayload = $this->buildSectionPayload(self::GRADE_7, ['name' => 'A']);
        $shsPayload = $this->buildSectionPayload(self::GRADE_11, ['name' => 'A']);

        foreach ([self::MISSING_ID, 'abc', true, 1.5, '99999999999999999999'] as $invalidSchoolYearId) {
            $this->assertRejectedField([...$jhsPayload, 'school_year_id' => $invalidSchoolYearId], 'school_year_id');
        }
        foreach ([self::MISSING_ID, 'abc'] as $invalidGradeLevelId) {
            $this->assertRejectedField([...$jhsPayload, 'grade_level_id' => $invalidGradeLevelId], 'grade_level_id');
        }
        foreach ([self::MISSING_ID, 'abc'] as $invalidProgramId) {
            $this->assertRejectedField([...$shsPayload, 'program_id' => $invalidProgramId], 'program_id');
        }

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_create_rejects_oversized_or_non_string_text(): void
    {
        $validPayload = $this->buildSectionPayload(self::GRADE_7, ['name' => 'A']);

        $this->assertRejectedField([...$validPayload, 'name' => str_repeat('n', 256)], 'name');
        $this->assertRejectedField([...$validPayload, 'name' => ['A']], 'name');
        $this->assertRejectedField([...$validPayload, 'room' => str_repeat('r', 256)], 'room');
        $this->assertRejectedField([...$validPayload, 'room' => 123], 'room');

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_create_accepts_max_length_text(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => str_repeat('n', 255), 'room' => str_repeat('r', 255)]))
            ->assertCreated()
            ->assertJsonPath('section.name', str_repeat('n', 255))
            ->assertJsonPath('section.room', str_repeat('r', 255));
    }

    public function test_create_ignores_unvalidated_keys(): void
    {
        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, [
                ...$this->buildSectionPayload(self::GRADE_7, ['name' => 'A']),
                'id' => 777,
                'created_at' => '2000-01-01 00:00:00',
                'enrolled_count' => 5,
                'term_id' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('section.enrolled_count', 0);

        $this->assertNotSame(777, $createResponse->json('section.id'));
        $this->assertStringStartsNotWith('2000', $createResponse->json('section.created_at'));
    }

    public function test_admin_updates_section(): void
    {
        $section = $this->createSection(self::GRADE_10, ['name' => 'Access-Post']);

        $updateResponse = $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_10, ['name' => 'Access-Post', 'capacity' => 45, 'room' => 'Rm 202']))
            ->assertOk()
            ->assertJsonPath('section.capacity', 45)
            ->assertJsonPath('section.room', 'Rm 202');

        $this->assertSame(self::SECTION_KEYS, array_keys($updateResponse->json('section')));
        $reloadedSection = $section->fresh();
        $this->assertSame(45, $reloadedSection->capacity);
        $this->assertSame('Rm 202', $reloadedSection->room);
    }

    public function test_update_allows_own_name_in_another_case(): void
    {
        $section = $this->createSection(self::GRADE_7, ['name' => 'Rizal']);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'RIZAL']))
            ->assertOk()
            ->assertJsonPath('section.name', 'RIZAL');
    }

    public function test_update_can_move_section_to_another_school_year_and_grade_level(): void
    {
        $otherYear = SchoolYear::factory()->create(['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-05-31']);
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, [...$this->buildSectionPayload(self::GRADE_8, ['name' => 'A']), 'school_year_id' => $otherYear->id])
            ->assertOk()
            ->assertJsonPath('section.school_year_id', $otherYear->id)
            ->assertJsonPath('section.grade_level.name', self::GRADE_8);
    }

    public function test_update_rejects_invalid_fields(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);
        $sectionUrl = self::SECTIONS_URL.'/'.$section->id;

        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'capacity' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity']);
        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_7, ['name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'program_id' => $stemProgram->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);

        $reloadedSection = $section->fresh();
        $this->assertSame(self::DEFAULT_CAPACITY, $reloadedSection->capacity);
        $this->assertNull($reloadedSection->program_id);
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.self::MISSING_ID, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id', 'grade_level_id', 'name', 'capacity']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.self::MISSING_ID, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A']))
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Section::class.'] '.self::MISSING_ID]);
    }

    public function test_admin_deletes_section(): void
    {
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);

        $this->withToken($this->adminToken)
            ->deleteJson(self::SECTIONS_URL.'/'.$section->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertNotNull($this->schoolYear->fresh());
    }

    public function test_delete_section_with_enrollments_returns_422(): void
    {
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);
        Enrollment::factory()->create(['section_id' => $section->id]);

        $this->withToken($this->adminToken)
            ->deleteJson(self::SECTIONS_URL.'/'.$section->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => SectionController::ENROLLMENTS_DELETE_MESSAGE]);

        $this->assertDatabaseHas('sections', ['id' => $section->id]);
    }

    public function test_update_section_with_enrollments_rejects_year_grade_level_and_program_change(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $abmProgram = Program::factory()->create(['code' => 'ABM', 'name' => 'Accountancy', 'stage' => GradeLevel::STAGE_SHS]);
        $otherYear = SchoolYear::factory()->create(['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-05-31']);
        $section = $this->createSection(self::GRADE_11, ['name' => 'STEM 11-A', 'program_id' => $stemProgram->id]);
        Enrollment::factory()->create(['section_id' => $section->id]);
        $sectionUrl = self::SECTIONS_URL.'/'.$section->id;
        $keptFields = ['name' => 'STEM 11-A', 'program_id' => $stemProgram->id];

        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, [...$this->buildSectionPayload(self::GRADE_11, $keptFields), 'school_year_id' => $otherYear->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => UpdateSectionRequest::ENROLLED_SECTION_LOCKED_MESSAGE]);
        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload('Grade 12', $keptFields))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id' => UpdateSectionRequest::ENROLLED_SECTION_LOCKED_MESSAGE]);
        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_11, ['name' => 'STEM 11-A', 'program_id' => $abmProgram->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => UpdateSectionRequest::ENROLLED_SECTION_LOCKED_MESSAGE]);

        $reloadedSection = $section->fresh();
        $this->assertSame($this->schoolYear->id, $reloadedSection->school_year_id);
        $this->assertSame($this->findGradeLevelId(self::GRADE_11), $reloadedSection->grade_level_id);
        $this->assertSame($stemProgram->id, $reloadedSection->program_id);
    }

    public function test_update_capacity_below_enrolled_count_returns_422_and_equal_passes(): void
    {
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);
        Enrollment::factory()->count(2)->create(['section_id' => $section->id]);
        $sectionUrl = self::SECTIONS_URL.'/'.$section->id;

        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'capacity' => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity' => UpdateSectionRequest::CAPACITY_BELOW_ENROLLED_MESSAGE]);
        $this->assertSame(self::DEFAULT_CAPACITY, $section->fresh()->capacity);

        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'capacity' => 2]))
            ->assertOk()
            ->assertJsonPath('section.capacity', 2)
            ->assertJsonPath('section.enrolled_count', 2);
    }

    public function test_update_section_with_enrollments_keeps_name_and_room_editable(): void
    {
        $section = $this->createSection(self::GRADE_7, ['name' => 'A']);
        Enrollment::factory()->create(['section_id' => $section->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'B', 'room' => 'Rm 5']))
            ->assertOk()
            ->assertJsonPath('section.name', 'B')
            ->assertJsonPath('section.room', 'Rm 5')
            ->assertJsonPath('section.enrolled_count', 1);
    }

    public function test_delete_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->adminToken)
            ->deleteJson(self::SECTIONS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Section::class.'] '.self::MISSING_ID]);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/abc', $this->buildSectionPayload(self::GRADE_7, ['name' => 'A']))
            ->assertNotFound();
        $this->withToken($this->adminToken)->deleteJson(self::SECTIONS_URL.'/abc')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    private function assertRejectedField(array $sectionPayload, string $rejectedField): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $sectionPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$rejectedField]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     * @return array<string, mixed>
     */
    private function buildSectionPayload(string $gradeLevelName, array $sectionFields): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId($gradeLevelName),
            'program_id' => null,
            'capacity' => self::DEFAULT_CAPACITY,
            'room' => null,
            ...$sectionFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createSection(string $gradeLevelName, array $sectionFields): Section
    {
        return Section::factory()->create($this->buildSectionPayload($gradeLevelName, $sectionFields));
    }

    private function findGradeLevelId(string $gradeLevelName): int
    {
        return GradeLevel::where('name', $gradeLevelName)->value('id');
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor(User::factory()->admin()->create(['email' => 'acting@admin.test']));
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
