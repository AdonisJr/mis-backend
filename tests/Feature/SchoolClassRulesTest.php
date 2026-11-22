<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class SchoolClassRulesTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    public function test_create_rejects_entry_of_another_school_year(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherYearEntry = $this->createJhsEntry(self::ENGLISH_7, ['school_year_id' => $otherYear->id]);

        $this->postClass(['curriculum_entry_id' => $otherYearEntry->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.curriculum_entry_id.0', StoreSchoolClassRequest::ENTRY_YEAR_MESSAGE);
    }

    public function test_create_rejects_entry_of_another_grade_level(): void
    {
        $gradeEightEntry = $this->createJhsEntry(self::ENGLISH_7, ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]);

        $this->postClass(['curriculum_entry_id' => $gradeEightEntry->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.curriculum_entry_id.0', StoreSchoolClassRequest::ENTRY_GRADE_LEVEL_MESSAGE);
    }

    public function test_create_rejects_shs_entry_of_another_program(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $abmProgram = $this->createProgram(self::ABM_PROGRAM);
        $stemSection = $this->createSection([
            'name' => 'STEM-A',
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'program_id' => $stemProgram->id,
        ]);
        $abmEntry = $this->createShsEntry($abmProgram, 1);

        $this->postClass(['section_id' => $stemSection->id, 'curriculum_entry_id' => $abmEntry->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.curriculum_entry_id.0', StoreSchoolClassRequest::ENTRY_PROGRAM_MESSAGE);
    }

    public function test_create_rejects_college_section(): void
    {
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id]);

        $this->postClass(['section_id' => $collegeSection->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.section_id.0', StoreSchoolClassRequest::SECTION_NOT_CURRICULAR_MESSAGE);
    }

    public function test_create_rejects_second_class_for_entry(): void
    {
        $this->postClass()->assertCreated();

        $this->postClass(['meetings' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.curriculum_entry_id.0', StoreSchoolClassRequest::DUPLICATE_CLASS_MESSAGE);

        $this->assertDatabaseCount('classes', 1);
    }

    public function test_same_entry_in_another_section_is_accepted(): void
    {
        $this->postClass()->assertCreated();
        $mabiniSection = $this->createSection(['name' => self::MABINI]);

        $this->postClass(['section_id' => $mabiniSection->id, 'meetings' => []])->assertCreated();
    }

    public function test_create_rejects_registrar_only_teacher(): void
    {
        $registrarOnly = User::factory()->registrar()->create();

        $this->postClass(['teacher_id' => $registrarOnly->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.teacher_id.0', StoreSchoolClassRequest::TEACHER_NOT_ACTIVE_MESSAGE);
    }

    public function test_create_rejects_inactive_teacher(): void
    {
        $inactiveTeacher = $this->createTeacher(['is_active' => false]);

        $this->postClass(['teacher_id' => $inactiveTeacher->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.teacher_id.0', StoreSchoolClassRequest::TEACHER_NOT_ACTIVE_MESSAGE);
    }

    public function test_create_accepts_registrar_who_is_also_teacher(): void
    {
        $teacherRegistrar = User::factory()->registrar()->teacher()->create();

        $this->postClass(['teacher_id' => $teacherRegistrar->id])->assertCreated();
    }

    public function test_update_keeps_unchanged_deactivated_teacher(): void
    {
        $schoolClass = $this->createClassForFixtures();
        $this->mathTeacher->update(['is_active' => false]);

        $this->putClass($schoolClass->id, ['teacher_id' => $this->mathTeacher->id, 'meetings' => []])->assertOk();
    }

    public function test_update_keeps_unchanged_role_less_teacher(): void
    {
        $schoolClass = $this->createClassForFixtures();
        $this->mathTeacher->roles()->detach();

        $this->putClass($schoolClass->id, ['teacher_id' => $this->mathTeacher->id, 'meetings' => []])->assertOk();
    }

    public function test_update_rejects_change_to_inactive_teacher(): void
    {
        $schoolClass = $this->createClassForFixtures();
        $inactiveTeacher = $this->createTeacher(['is_active' => false]);

        $this->putClass($schoolClass->id, ['teacher_id' => $inactiveTeacher->id, 'meetings' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.teacher_id.0', StoreSchoolClassRequest::TEACHER_NOT_ACTIVE_MESSAGE);
    }

    /**
     * @return array<string, array{string, bool, mixed}>
     */
    public static function provideInvalidIds(): array
    {
        return [
            'section missing' => ['section_id', false, null],
            'section unknown' => ['section_id', true, 999],
            'section text' => ['section_id', true, 'abc'],
            'entry missing' => ['curriculum_entry_id', false, null],
            'entry unknown' => ['curriculum_entry_id', true, 999],
            'entry decimal' => ['curriculum_entry_id', true, '1.5'],
            'teacher missing' => ['teacher_id', false, null],
            'teacher unknown' => ['teacher_id', true, 999],
            'teacher boolean' => ['teacher_id', true, true],
        ];
    }

    #[DataProvider('provideInvalidIds')]
    public function test_create_rejects_invalid_ids(string $field, bool $isSent, mixed $value): void
    {
        $payload = $this->buildClassPayload();
        unset($payload[$field]);

        $this->withToken($this->adminToken)
            ->postJson(self::CLASSES_URL, $isSent ? [...$payload, $field => $value] : $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('classes', 0);
    }

    public function test_section_with_classes_rejects_grade_level_change(): void
    {
        $this->createClassForFixtures();

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$this->rizalSection->id, $this->buildSectionPayload(self::GRADE_8, ['name' => self::RIZAL]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.grade_level_id.0', UpdateSectionRequest::CLASSES_SECTION_LOCKED_MESSAGE);
    }

    public function test_section_with_classes_accepts_name_change(): void
    {
        $this->createClassForFixtures();

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$this->rizalSection->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal 2']))
            ->assertOk()
            ->assertJsonPath('section.name', 'Rizal 2');
    }
}
