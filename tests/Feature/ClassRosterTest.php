<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class ClassRosterTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const MISSING_CLASS_ID = 9999;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
    }

    public function test_header_has_eleven_keys_with_school_year_and_term_label(): void
    {
        $this->createTerm(1);
        $shsClass = $this->createShsClass($this->mathTeacher, 1);

        $header = $this->withToken($this->adminToken)->getJson($this->rosterUrl($shsClass->id))
            ->assertOk()
            ->assertJsonStructure(['class' => self::CLASS_LIST_CLASS_KEYS, 'students', 'left_the_class'])
            ->json('class');

        $this->assertSame(self::CLASS_LIST_CLASS_KEYS, array_keys($header));
        $this->assertSame(['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name, 'term_type' => 'semester'], $header['school_year']);
        $this->assertSame('1st Semester', $header['term_label']);
        $this->assertSame(1, $header['term_number']);
        $this->assertSame(['id' => $this->stemProgram->id, 'code' => $this->stemProgram->code, 'name' => $this->stemProgram->name], $header['program']);
    }

    public function test_jhs_roster_holds_only_the_section_rows(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $otherSection = $this->createSection(['name' => self::MABINI]);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $otherSection->id]);

        $rosterResponse = $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))->assertOk();

        $this->assertSame([self::AQUINO], $this->pluckLastNames($rosterResponse->json('students')));
        $this->assertSame(self::ROSTER_ROW_KEYS, array_slice(array_keys($rosterResponse->json('students.0')), 0, count(self::ROSTER_ROW_KEYS)));
    }

    public function test_shs_roster_holds_only_the_class_term(): void
    {
        $termOne = $this->createTerm(1);
        $termTwo = $this->createTerm(2);
        $termOneClass = $this->createShsClass($this->mathTeacher, 1);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->stemSection->id, 'term_id' => $termOne->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->stemSection->id, 'term_id' => $termTwo->id]);

        $this->assertSame([self::AQUINO], $this->pluckLastNames($this->withToken($this->adminToken)->getJson($this->rosterUrl($termOneClass->id))->assertOk()->json('students')));
    }

    public function test_students_are_ordered_by_last_name_first_name_and_id(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $sectionFields = ['section_id' => $this->rizalSection->id];
        $bonifacio = $this->enrollStudent(['last_name' => self::BONIFACIO, 'first_name' => 'Ana'], $sectionFields);
        $zedAquino = $this->enrollStudent(['last_name' => self::AQUINO, 'first_name' => 'Zed'], $sectionFields);
        $firstAna = $this->enrollStudent(['last_name' => self::AQUINO, 'first_name' => 'Ana'], $sectionFields);
        $secondAna = $this->enrollStudent(['last_name' => self::AQUINO, 'first_name' => 'Ana'], $sectionFields);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonPath('students.*.enrollment_id', [$firstAna->id, $secondAna->id, $zedAquino->id, $bonifacio->id]);
    }

    public function test_listed_group_holds_enrolled_and_completed(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_COMPLETED]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonPath('students.*.status', [Enrollment::STATUS_ENROLLED, Enrollment::STATUS_COMPLETED])
            ->assertJsonPath('left_the_class', []);
    }

    public function test_left_group_holds_moved_dropped_and_transferred(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED]);
        $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_TRANSFERRED]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonPath('left_the_class.*.status', [Enrollment::STATUS_MOVED, Enrollment::STATUS_DROPPED, Enrollment::STATUS_TRANSFERRED]);
    }

    public function test_student_moved_back_is_listed_once(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $student = Student::factory()->create(['last_name' => self::AQUINO]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.status', Enrollment::STATUS_ENROLLED)
            ->assertJsonPath('left_the_class', []);
    }

    public function test_student_with_two_left_rows_appears_once(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $student = Student::factory()->create(['last_name' => self::AQUINO]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonCount(1, 'left_the_class')
            ->assertJsonPath('left_the_class.0.status', Enrollment::STATUS_DROPPED);
    }

    public function test_older_listed_row_beats_newer_left_row(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $student = Student::factory()->create(['last_name' => self::AQUINO]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_COMPLETED]);
        $this->enrollExistingStudent($student, ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.status', Enrollment::STATUS_COMPLETED)
            ->assertJsonPath('left_the_class', [])
            ->assertJsonPath('class.student_count', 1);
    }

    public function test_empty_roster_returns_empty_lists_and_zero_count(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonPath('left_the_class', [])
            ->assertJsonPath('class.student_count', 0);
    }

    public function test_student_count_equals_the_students_length(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_COMPLETED]);
        $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED]);

        $rosterResponse = $this->withToken($this->adminToken)->getJson($this->rosterUrl($schoolClass->id))->assertOk();

        $this->assertSame(count($rosterResponse->json('students')), $rosterResponse->json('class.student_count'));
        $this->assertSame(2, $rosterResponse->json('class.student_count'));
    }

    public function test_shs_class_without_term_row_has_empty_roster(): void
    {
        $shsClass = $this->createShsClass($this->mathTeacher, 1);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->stemSection->id, 'term_id' => $this->createTerm(2)->id]);

        $this->withToken($this->adminToken)->getJson($this->rosterUrl($shsClass->id))
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonPath('left_the_class', [])
            ->assertJsonPath('class.student_count', 0);
    }

    public function test_missing_class_gets_404(): void
    {
        $this->withToken($this->adminToken)->getJson($this->rosterUrl(self::MISSING_CLASS_ID))
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_non_numeric_class_id_gets_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::CLASSES_URL.'/abc/roster')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
