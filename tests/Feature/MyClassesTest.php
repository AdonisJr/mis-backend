<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class MyClassesTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private User $teacher;

    private string $teacherToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->teacher = $this->createTeacher(['email' => 'mine@school.test']);
        $this->teacherToken = $this->createTokenFor($this->teacher);
    }

    public function test_teacher_lists_own_classes_only(): void
    {
        $ownClass = $this->createJhsClass($this->teacher);
        $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('teacher', ['id' => $this->teacher->id, 'name' => $this->teacher->name])
            ->assertJsonCount(1, 'classes')
            ->assertJsonPath('classes.0.id', $ownClass->id);
    }

    public function test_class_has_eleven_keys_and_meeting_keys(): void
    {
        $this->createJhsClass($this->teacher);

        $classRow = $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonStructure(['classes' => ['*' => ['meetings' => ['*' => self::MEETING_KEYS]]]])
            ->json('classes.0');

        $this->assertSame(self::CLASS_LIST_CLASS_KEYS, array_keys($classRow));
        $this->assertNull($classRow['program']);
        $this->assertNull($classRow['term_label']);
        $this->assertNull($classRow['term_number']);
        $this->assertSame(['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name, 'term_type' => SchoolYear::TERM_TYPE_SEMESTER], $classRow['school_year']);
        $this->assertSame(['id' => $this->rizalSection->id, 'name' => self::RIZAL], $classRow['section']);
    }

    public function test_term_filter_keeps_term_one_shs_and_jhs_and_drops_term_two(): void
    {
        $jhsClass = $this->createJhsClass($this->teacher);
        $termOneClass = $this->createShsClass($this->teacher, 1);
        $this->createShsClass($this->teacher, 2);

        $classIds = $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'term_number' => 1]))
            ->assertOk()
            ->assertJsonPath('classes.1.term_label', '1st Semester')
            ->json('classes.*.id');

        $this->assertEqualsCanonicalizing([$jhsClass->id, $termOneClass->id], $classIds);
    }

    public function test_no_term_returns_whole_year(): void
    {
        $this->createJhsClass($this->teacher);
        $this->createShsClass($this->teacher, 1);
        $this->createShsClass($this->teacher, 2);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonCount(3, 'classes');
    }

    public function test_other_school_year_is_excluded(): void
    {
        $this->createJhsClass($this->teacher);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $otherYear->id]))
            ->assertOk()
            ->assertExactJson(['teacher' => ['id' => $this->teacher->id, 'name' => $this->teacher->name], 'classes' => []]);
    }

    public function test_student_count_is_enrolled_plus_completed(): void
    {
        $schoolClass = $this->createJhsClass($this->teacher);
        $otherSection = $this->createSection(['name' => self::MABINI]);
        $sectionFields = ['section_id' => $this->rizalSection->id];

        $this->enrollStudent(['last_name' => self::AQUINO], $sectionFields);
        $this->enrollStudent(['last_name' => self::BONIFACIO], [...$sectionFields, 'status' => Enrollment::STATUS_COMPLETED]);
        $this->enrollStudent(['last_name' => self::RAMOS], [...$sectionFields, 'status' => Enrollment::STATUS_MOVED]);
        $this->enrollStudent(['last_name' => self::JOSE], [...$sectionFields, 'status' => Enrollment::STATUS_DROPPED]);
        $this->enrollStudent(['last_name' => self::MARIA], [...$sectionFields, 'status' => Enrollment::STATUS_TRANSFERRED]);
        $this->enrollStudent(['last_name' => 'Elsewhere'], ['section_id' => $otherSection->id]);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('classes.0.id', $schoolClass->id)
            ->assertJsonPath('classes.0.student_count', 2);
    }

    public function test_shs_count_uses_only_its_term_rows(): void
    {
        $termOne = $this->createTerm(1);
        $termTwo = $this->createTerm(2);
        $this->createShsClass($this->teacher, 1);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->stemSection->id, 'term_id' => $termOne->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->stemSection->id, 'term_id' => $termOne->id]);
        $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->stemSection->id, 'term_id' => $termTwo->id]);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('classes.0.student_count', 2);
    }

    public function test_shs_count_is_zero_without_term_row(): void
    {
        $this->createShsClass($this->teacher, 1);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->stemSection->id, 'term_id' => $this->createTerm(2)->id]);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('classes.0.student_count', 0);
    }

    public function test_former_teacher_no_longer_listed(): void
    {
        $schoolClass = $this->createJhsClass($this->teacher);
        $schoolClass->update(['teacher_id' => $this->mathTeacher->id]);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonCount(0, 'classes');
    }

    public function test_classes_are_ordered_by_grade_level_section_and_curriculum(): void
    {
        $gradeEightSection = $this->createSection(['name' => 'Aaa', 'grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]);
        $mabiniSection = $this->createSection(['name' => self::MABINI]);
        $laterEntry = $this->createJhsEntry('LATER', ['sort_order' => 2]);
        $earlierEntry = $this->createJhsEntry('EARLIER', ['sort_order' => 1]);
        $gradeEightEntry = $this->createJhsEntry('GRADE8', ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]);
        $gradeEightClass = $this->createScheduledClass(['section_id' => $gradeEightSection->id, 'curriculum_entry_id' => $gradeEightEntry->id, 'teacher_id' => $this->teacher->id]);
        $laterClass = $this->createScheduledClass(['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $laterEntry->id, 'teacher_id' => $this->teacher->id]);
        $earlierClass = $this->createScheduledClass(['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $earlierEntry->id, 'teacher_id' => $this->teacher->id]);
        $mabiniClass = $this->createScheduledClass(['section_id' => $mabiniSection->id, 'curriculum_entry_id' => $laterEntry->id, 'teacher_id' => $this->teacher->id]);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('classes.*.id', [$mabiniClass->id, $earlierClass->id, $laterClass->id, $gradeEightClass->id]);
    }

    public function test_missing_school_year_gets_422(): void
    {
        $this->withToken($this->teacherToken)->getJson(self::MY_CLASSES_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_term_number_three_on_a_semester_year_gets_422(): void
    {
        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'term_number' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);
    }

    public function test_admin_with_unknown_teacher_id_gets_422(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_admin_with_teacher_id_lists_that_teachers_classes(): void
    {
        $schoolClass = $this->createJhsClass($this->teacher);

        $this->withToken($this->adminToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->teacher->id]))
            ->assertOk()
            ->assertJsonPath('teacher.id', $this->teacher->id)
            ->assertJsonPath('classes.0.id', $schoolClass->id);
    }

    public function test_registrar_with_teacher_id_lists_that_teachers_classes(): void
    {
        $schoolClass = $this->createJhsClass($this->teacher);

        $this->withToken($this->createRegistrarToken())->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->teacher->id]))
            ->assertOk()
            ->assertJsonPath('classes.0.id', $schoolClass->id);
    }

    public function test_admin_without_teacher_id_gets_an_empty_list(): void
    {
        $this->createJhsClass($this->teacher);

        $this->withToken($this->adminToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonCount(0, 'classes');
    }

    public function test_teacher_with_foreign_teacher_id_gets_403(): void
    {
        foreach ([$this->mathTeacher->id, 'abc', 999] as $foreignId) {
            $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $foreignId]))
                ->assertForbidden()
                ->assertExactJson(self::FOREIGN_TEACHER_BODY);
        }
    }

    public function test_teacher_with_own_teacher_id_gets_200(): void
    {
        $schoolClass = $this->createJhsClass($this->teacher);

        $this->withToken($this->teacherToken)->getJson($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->teacher->id]))
            ->assertOk()
            ->assertJsonPath('classes.0.id', $schoolClass->id);
    }

    public function test_query_count_does_not_grow_with_classes(): void
    {
        $this->createJhsClass($this->teacher);
        // the first request loads the authenticated user, later ones in the same test reuse it
        $this->countQueriesFor($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]));
        $oneClassCount = $this->countQueriesFor($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]));

        $this->createJhsClass($this->teacher);
        $this->createJhsClass($this->teacher);
        $threeClassCount = $this->countQueriesFor($this->buildMyClassesUrl(['school_year_id' => $this->schoolYear->id]));

        $this->assertSame($oneClassCount, $threeClassCount);
    }

    private function countQueriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($this->teacherToken)->getJson($url)->assertOk();
        $appQueries = array_filter(DB::getQueryLog(), fn (array $query) => ! str_contains($query['query'], 'personal_access_tokens'));
        DB::disableQueryLog();

        return count($appQueries);
    }
}
