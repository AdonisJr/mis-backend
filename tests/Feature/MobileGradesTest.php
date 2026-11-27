<?php

namespace Tests\Feature;

use App\Http\Requests\ShowClassQuarterRequest;
use App\Http\Requests\ShowReportCardRequest;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class MobileGradesTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private const GRADES_URL = '/api/mis/mobile/grades';

    private const TOP_KEYS = ['school_year', 'school_years', 'report_card'];

    private const TRIMESTER_YEAR = ['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31', 'term_type' => SchoolYear::TERM_TYPE_TRIMESTER];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
    }

    /**
     * @param  list<int>  $quarterlyGrades  grades of quarters 1..n, each submitted for the student
     */
    private function submitGrades(SchoolClass $schoolClass, Student $student, array $quarterlyGrades): void
    {
        foreach ($quarterlyGrades as $index => $quarterlyGrade) {
            $this->createSubmittedQuarter($schoolClass, [['student_id' => $student->id, 'quarterly_grade' => $quarterlyGrade]], $index + 1);
        }
    }

    private function enrollInRizal(string $lastName, string $status = Enrollment::STATUS_ENROLLED): Student
    {
        return $this->enrollStudent(['last_name' => $lastName], ['section_id' => $this->rizalSection->id, 'status' => $status])->student;
    }

    private function createSecondTerm(): void
    {
        $this->createTerm(1);
        $this->createTerm(2);
    }

    private function enrollShsStudent(string $lastName, array $termNumbers): Student
    {
        $student = Student::factory()->create(['last_name' => $lastName]);

        foreach ($termNumbers as $termNumber) {
            $termId = Term::where('school_year_id', $this->schoolYear->id)->where('term_number', $termNumber)->value('id');
            $this->enrollExistingStudent($student, ['section_id' => $this->stemSection->id, 'term_id' => $termId]);
        }

        return $student;
    }

    private function createStudentToken(Student $student): string
    {
        return $this->createTokenFor(User::factory()->studentAccount($student)->create());
    }

    /**
     * @param  array<string, mixed>  $yearFields
     */
    private function createOtherYearSection(array $yearFields): Section
    {
        $otherYear = SchoolYear::factory()->create($yearFields);

        return $this->createSection(['name' => 'Mabini', 'school_year_id' => $otherYear->id]);
    }

    /** @return array<string, mixed> */
    private function fetchGrades(Student $student, array $query = []): array
    {
        // one app instance serves every request of the test, so the guard must drop the previous student
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->createStudentToken($student))->getJson(self::GRADES_URL.'?'.http_build_query($query))->assertOk()->json();
    }

    public function test_jhs_grades_hold_quarters_final_remarks_and_the_general_average(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88, 89, 90, 91]);

        $subjectRow = $this->fetchGrades($student)['report_card']['subjects'][0];

        $this->assertSame([1, 2, 3, 4], array_column($subjectRow['quarters'], 'quarter'));
        $this->assertSame([88, 89, 90, 91], array_column($subjectRow['quarters'], 'grade'));
        $this->assertSame(90, $subjectRow['final_grade']);
        $this->assertSame('Passed', $subjectRow['remarks']);
        $this->assertSame('90.00', $this->fetchGrades($student)['report_card']['general_averages'][0]['average']);
    }

    public function test_shs_grades_have_two_quarters_per_subject_and_two_averages(): void
    {
        $this->createSecondTerm();
        $student = $this->enrollShsStudent(self::AQUINO, [1, 2]);
        $firstTermClass = $this->createShsClass($this->mathTeacher, 1);
        $secondTermClass = $this->createShsClass($this->mathTeacher, 2);
        $this->createSubmittedQuarter($firstTermClass, [['student_id' => $student->id, 'quarterly_grade' => 84]], 1);
        $this->createSubmittedQuarter($firstTermClass, [['student_id' => $student->id, 'quarterly_grade' => 86]], 2);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 90]], 3);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 92]], 4);

        $reportCard = $this->fetchGrades($student)['report_card'];

        $this->assertSame([1, 2], array_column($reportCard['subjects'][0]['quarters'], 'quarter'));
        $this->assertSame([3, 4], array_column($reportCard['subjects'][1]['quarters'], 'quarter'));
        $this->assertSame(['85.00', '91.00'], array_column($reportCard['general_averages'], 'average'));
    }

    public function test_unsubmitted_quarter_is_null(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88, 89, 90]);

        $subjectRow = $this->fetchGrades($student)['report_card']['subjects'][0];

        $this->assertNull($subjectRow['quarters'][3]['grade']);
        $this->assertNull($subjectRow['final_grade']);
    }

    public function test_only_live_scores_without_a_submitted_quarter_stay_null(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $activity = $this->createActivity($this->createClassForFixtures());
        $this->createScore($activity, $student, '18');

        $this->assertSame([null, null, null, null], array_column($this->fetchGrades($student)['report_card']['subjects'][0]['quarters'], 'grade'));
    }

    public function test_default_year_is_the_active_year(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88]);
        $newerSection = $this->createOtherYearSection(self::SCHOOL_YEAR_2031);
        $this->enrollExistingStudent($student, ['section_id' => $newerSection->id]);

        $grades = $this->fetchGrades($student);

        $this->assertSame(['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name], $grades['school_year']);
    }

    public function test_default_year_falls_back_to_the_newest_graded_year(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88]);
        $newerSection = $this->createOtherYearSection(self::SCHOOL_YEAR_2031);
        $this->enrollExistingStudent($student, ['section_id' => $newerSection->id]);
        SchoolYear::query()->update(['is_active' => false]);

        $grades = $this->fetchGrades($student);

        $this->assertSame($this->schoolYear->id, $grades['school_year']['id']);
        $this->assertSame(88, $grades['report_card']['subjects'][0]['quarters'][0]['grade']);
    }

    public function test_student_without_an_enrollment_gets_an_empty_list_and_no_report_card(): void
    {
        $grades = $this->fetchGrades(Student::factory()->create());

        $this->assertNull($grades['school_year']);
        $this->assertSame([], $grades['school_years']);
        $this->assertNull($grades['report_card']);
    }

    public function test_year_list_is_newest_first_and_skips_trimester_and_dropped_only_years(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $newerSection = $this->createOtherYearSection(self::SCHOOL_YEAR_2031);
        $this->enrollExistingStudent($student, ['section_id' => $newerSection->id]);
        $trimesterSection = $this->createOtherYearSection(self::TRIMESTER_YEAR);
        $this->enrollExistingStudent($student, ['section_id' => $trimesterSection->id]);
        $droppedSection = $this->createOtherYearSection(['name' => '2033-2034', 'start_date' => '2033-06-01', 'end_date' => '2034-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER]);
        $this->enrollExistingStudent($student, ['section_id' => $droppedSection->id, 'status' => Enrollment::STATUS_DROPPED]);

        $yearNames = array_column($this->fetchGrades($student)['school_years'], 'name');

        $this->assertSame([self::SCHOOL_YEAR_2031['name'], $this->schoolYear->name], $yearNames);
    }

    public function test_explicit_school_year_id_selects_that_year(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $newerSection = $this->createOtherYearSection(self::SCHOOL_YEAR_2031);
        $this->enrollExistingStudent($student, ['section_id' => $newerSection->id]);

        $grades = $this->fetchGrades($student, ['school_year_id' => $newerSection->school_year_id]);

        $this->assertSame($newerSection->school_year_id, $grades['school_year']['id']);
        $this->assertSame($newerSection->id, $grades['report_card']['section']['id']);
    }

    public function test_grades_match_the_admin_report_card_for_jhs_and_shs_students(): void
    {
        $this->createSecondTerm();
        $jhsStudent = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $jhsStudent, [88, 89, 90, 91]);
        $shsStudent = $this->enrollShsStudent(self::BONIFACIO, [1, 2]);
        $this->createSubmittedQuarter($this->createShsClass($this->mathTeacher, 1), [['student_id' => $shsStudent->id, 'quarterly_grade' => 84]], 1);

        foreach ([$jhsStudent, $shsStudent] as $student) {
            $this->app['auth']->forgetGuards();
            $adminCard = $this->withToken($this->adminToken)->getJson($this->reportCardUrl($student, $this->schoolYear->id))->assertOk()->json('report_card');

            $this->assertSame($adminCard, $this->fetchGrades($student)['report_card']);
        }
    }

    public function test_trimester_school_year_returns_422(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $trimesterYear = SchoolYear::factory()->trimester()->create();

        $this->withToken($this->createStudentToken($student))->getJson(self::GRADES_URL.'?school_year_id='.$trimesterYear->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => ShowClassQuarterRequest::TRIMESTER_MESSAGE]);
    }

    public function test_year_without_an_enrollment_returns_422(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $otherSection = $this->createOtherYearSection(self::SCHOOL_YEAR_2031);

        $this->withToken($this->createStudentToken($student))->getJson(self::GRADES_URL.'?school_year_id='.$otherSection->school_year_id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => ShowReportCardRequest::NO_ENROLLMENT_MESSAGE]);
    }

    public function test_invalid_school_year_id_returns_422(): void
    {
        $studentToken = $this->createStudentToken($this->enrollInRizal(self::AQUINO));

        foreach (['abc', '-1', '999'] as $invalidId) {
            $this->withToken($studentToken)->getJson(self::GRADES_URL.'?school_year_id='.$invalidId)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['school_year_id']);
        }
    }

    public function test_grades_body_has_exactly_the_three_top_keys(): void
    {
        $this->assertSame(self::TOP_KEYS, array_keys($this->fetchGrades($this->enrollInRizal(self::AQUINO))));
    }
}
