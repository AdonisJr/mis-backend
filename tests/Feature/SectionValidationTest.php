<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSectionRequest;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SectionValidationTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const DEFAULT_CAPACITY = 40;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2032 = ['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const ABM_PROGRAM = ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS];

    private const GAS_PROGRAM = ['code' => 'GAS', 'name' => 'General Academic Strand', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    private const GRADE_7 = 'Grade 7';

    private const GRADE_8 = 'Grade 8';

    private const GRADE_9 = 'Grade 9';

    private const GRADE_10 = 'Grade 10';

    private const GRADE_11 = 'Grade 11';

    private const GRADE_12 = 'Grade 12';

    private const FIRST_YEAR_COLLEGE = '1st Year College';

    private SchoolYear $schoolYear;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->adminToken = $this->createAdminToken();
    }

    public function test_jhs_section_rejects_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);

        $this->postSection($this->buildSectionPayload(self::GRADE_7, ['program_id' => $stemProgram->id, 'name' => 'JHS-Prog']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);
        $this->postSection($this->buildSectionPayload(self::GRADE_10, ['program_id' => $bsitProgram->id, 'name' => 'JHS-Prog']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, 'search' => 'JHS-Prog']))
            ->assertOk()
            ->assertJsonPath('sections', []);
    }

    public function test_shs_section_requires_program(): void
    {
        $shsPayload = $this->buildSectionPayload(self::GRADE_11, ['name' => 'SHS-NoProg']);

        $this->postSection($shsPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_REQUIRED_MESSAGE]);
        $this->postSection(Arr::except($shsPayload, 'program_id'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_REQUIRED_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_college_section_requires_program(): void
    {
        $this->postSection($this->buildSectionPayload(self::FIRST_YEAR_COLLEGE, ['name' => 'COL-NoProg']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_REQUIRED_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_shs_section_rejects_college_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);

        $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'SHS-OK']))
            ->assertCreated()
            ->assertJsonPath('section.program.code', 'STEM');
        $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $bsitProgram->id, 'name' => 'SHS-College']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_STAGE_MESSAGE]);

        $this->assertDatabaseCount('sections', 1);
    }

    public function test_college_section_rejects_shs_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);

        $this->postSection($this->buildSectionPayload(self::FIRST_YEAR_COLLEGE, ['program_id' => $bsitProgram->id, 'name' => 'COL-OK']))
            ->assertCreated()
            ->assertJsonPath('section.program.code', 'BSIT');
        $this->postSection($this->buildSectionPayload(self::FIRST_YEAR_COLLEGE, ['program_id' => $stemProgram->id, 'name' => 'COL-SHS']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_STAGE_MESSAGE]);

        $this->assertDatabaseCount('sections', 1);
    }

    public function test_create_rejects_inactive_program(): void
    {
        $gasProgram = Program::factory()->inactive()->create(self::GAS_PROGRAM);

        $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $gasProgram->id, 'name' => 'Inactive-New']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_update_rejects_change_to_inactive_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $gasProgram = Program::factory()->inactive()->create(self::GAS_PROGRAM);
        $section = $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'Change-Prog']);

        $this->putSection($section, $this->buildSectionPayload(self::GRADE_11, ['program_id' => $gasProgram->id, 'name' => 'Change-Prog']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE]);

        $this->assertSame($stemProgram->id, $section->fresh()->program_id);
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, 'search' => 'Change-Prog']))
            ->assertOk()
            ->assertJsonPath('sections.0.program.code', 'STEM');
    }

    public function test_update_keeps_existing_inactive_program(): void
    {
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $section = $this->createSection(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']);
        $abmProgram->forceFill(['is_active' => false])->save();

        $this->putSection($section, $this->buildSectionPayload(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog', 'capacity' => 35]))
            ->assertOk()
            ->assertJsonPath('section.program.code', 'ABM')
            ->assertJsonPath('section.capacity', 35);
        $this->putSection($section, $this->buildSectionPayload(self::GRADE_11, ['program_id' => (string) $abmProgram->id, 'name' => 'Keep-Prog']))
            ->assertOk()
            ->assertJsonPath('section.program.code', 'ABM');
    }

    public function test_update_keeps_inactive_program_within_same_stage(): void
    {
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $section = $this->createSection(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']);
        $abmProgram->forceFill(['is_active' => false])->save();

        $this->putSection($section, $this->buildSectionPayload(self::GRADE_12, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']))
            ->assertOk()
            ->assertJsonPath('section.grade_level.name', self::GRADE_12)
            ->assertJsonPath('section.program.code', 'ABM');
    }

    public function test_update_with_kept_inactive_program_still_checks_stage(): void
    {
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $section = $this->createSection(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']);
        $abmProgram->forceFill(['is_active' => false])->save();

        $this->putSection($section, $this->buildSectionPayload(self::FIRST_YEAR_COLLEGE, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_STAGE_MESSAGE]);
        $this->putSection($section, $this->buildSectionPayload(self::GRADE_7, ['program_id' => $abmProgram->id, 'name' => 'Keep-Prog']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);

        $reloadedSection = $section->fresh();
        $this->assertSame($this->findGradeLevelId(self::GRADE_11), $reloadedSection->grade_level_id);
        $this->assertSame($abmProgram->id, $reloadedSection->program_id);
    }

    public function test_program_rules_wait_for_valid_ids(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);

        $this->postSection([...$this->buildSectionPayload(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'A']), 'grade_level_id' => self::MISSING_ID])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id'])
            ->assertJsonMissingValidationErrors(['program_id']);

        $unknownProgramResponse = $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => self::MISSING_ID, 'name' => 'A']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id']);
        $this->assertCount(1, $unknownProgramResponse->json('errors.program_id'));
    }

    public function test_name_is_unique_ignoring_case_within_group(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'Rizal']);

        foreach (['rizal', 'RIZAL', ' Rizal '] as $duplicateName) {
            $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => $duplicateName]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);
        }

        $this->assertDatabaseCount('sections', 1);
    }

    public function test_jhs_sections_without_program_share_one_name_group(): void
    {
        $this->createSection(self::GRADE_7, ['name' => 'A']);
        $jhsPayload = $this->buildSectionPayload(self::GRADE_7, ['name' => 'a']);

        $this->postSection($jhsPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);
        $this->postSection(Arr::except($jhsPayload, 'program_id'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('sections', 1);
    }

    public function test_same_name_is_allowed_in_other_groups(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'Rizal']);
        $this->createSection(self::GRADE_7, ['name' => 'A']);

        $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'Rizal']))->assertCreated();
        $this->postSection($this->buildSectionPayload(self::GRADE_12, ['program_id' => $stemProgram->id, 'name' => 'Rizal']))->assertCreated();
        $this->postSection($this->buildSectionPayload(self::GRADE_8, ['name' => 'A']))->assertCreated();
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2032);
        $this->postSection([...$this->buildSectionPayload(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'Rizal']), 'school_year_id' => $otherYear->id])
            ->assertCreated();

        $this->assertDatabaseCount('sections', 6);
    }

    public function test_update_rejects_name_taken_in_target_group(): void
    {
        $gradeSevenA = $this->createSection(self::GRADE_7, ['name' => 'A']);
        $gradeSevenB = $this->createSection(self::GRADE_7, ['name' => 'B']);
        $this->createSection(self::GRADE_8, ['name' => 'A']);

        $this->putSection($gradeSevenB, $this->buildSectionPayload(self::GRADE_7, ['name' => 'a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);
        $this->putSection($gradeSevenA, $this->buildSectionPayload(self::GRADE_8, ['name' => 'A']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);

        $this->assertSame('B', $gradeSevenB->fresh()->name);
        $this->assertSame($this->findGradeLevelId(self::GRADE_7), $gradeSevenA->fresh()->grade_level_id);
    }

    public function test_name_check_waits_for_valid_group(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'Rizal']);

        $this->postSection($this->buildSectionPayload(self::GRADE_11, ['program_id' => $bsitProgram->id, 'name' => 'Rizal']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id'])
            ->assertJsonMissingValidationErrors(['name']);
    }

    public function test_exact_duplicate_non_ascii_name_is_rejected(): void
    {
        $this->createSection(self::GRADE_9, ['name' => 'Ñandú']);

        $this->postSection($this->buildSectionPayload(self::GRADE_9, ['name' => 'Ñandú']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreSectionRequest::NAME_TAKEN_MESSAGE]);
        $this->postSection($this->buildSectionPayload(self::GRADE_9, ['name' => 'Rizal Annex']))
            ->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    private function postSection(array $sectionPayload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::SECTIONS_URL, $sectionPayload);
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    private function putSection(Section $section, array $sectionPayload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::SECTIONS_URL.'/'.$section->id, $sectionPayload);
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
