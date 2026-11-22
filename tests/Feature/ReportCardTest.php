<?php

namespace Tests\Feature;

use App\Http\Requests\ShowClassQuarterRequest;
use App\Http\Requests\ShowReportCardRequest;
use App\Http\Requests\ShowSectionReportCardsRequest;
use App\Models\ClassQuarter;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
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

    /** @return array<string, mixed> */
    private function fetchCard(Student $student): array
    {
        return $this->withToken($this->adminToken)->getJson($this->reportCardUrl($student, $this->schoolYear->id))->assertOk()->json('report_card');
    }

    private function createSecondTerm(): void
    {
        $this->createTerm(1);
        $this->createTerm(2);
    }

    public function test_report_card_has_eight_keys_and_the_student_identity(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->rizalSection->update(['adviser_id' => $this->mathTeacher->id]);

        $card = $this->fetchCard($student);

        $this->assertSame(self::REPORT_CARD_KEYS, array_keys($card));
        $this->assertSame($student->id, $card['student']['id']);
        $this->assertSame(['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name, 'term_type' => 'semester'], $card['school_year']);
        $this->assertSame(['id' => $this->rizalSection->id, 'name' => self::RIZAL], $card['section']);
        $this->assertSame(['id' => $this->mathTeacher->id, 'name' => $this->mathTeacher->name], $card['adviser']);
        $this->assertNull($card['program']);
    }

    public function test_jhs_card_with_four_submitted_quarters_has_final_remarks_and_general_average(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88, 89, 90, 91]);

        $card = $this->fetchCard($student);

        $subjectRow = $card['subjects'][0];
        $this->assertSame([1, 2, 3, 4], array_column($subjectRow['quarters'], 'quarter'));
        $this->assertSame([88, 89, 90, 91], array_column($subjectRow['quarters'], 'grade'));
        $this->assertSame(90, $subjectRow['final_grade']);
        $this->assertSame('Passed', $subjectRow['remarks']);
        $this->assertSame([['term_number' => null, 'average' => '90.00', 'rounded_average' => 90, 'descriptor' => 'Outstanding']], $card['general_averages']);
    }

    public function test_unsubmitted_quarter_is_blank_and_the_final_and_average_are_null(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $this->submitGrades($this->createClassForFixtures(), $student, [88, 89, 90]);

        $card = $this->fetchCard($student);

        $this->assertNull($card['subjects'][0]['quarters'][3]['grade']);
        $this->assertNull($card['subjects'][0]['final_grade']);
        $this->assertNull($card['subjects'][0]['remarks']);
        $this->assertSame([['term_number' => null, 'average' => null, 'rounded_average' => null, 'descriptor' => null]], $card['general_averages']);
    }

    public function test_reopened_quarter_is_blank(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        $schoolClass = $this->createClassForFixtures();
        $this->submitGrades($schoolClass, $student, [88]);
        ClassQuarter::where('class_id', $schoolClass->id)->firstOrFail()->grades()->delete();

        $this->assertNull($this->fetchCard($student)['subjects'][0]['quarters'][0]['grade']);
    }

    public function test_entry_without_a_class_is_blank(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);

        $subjectRow = $this->fetchCard($student)['subjects'][0];

        $this->assertSame($this->mathEntry->id, $subjectRow['curriculum_entry_id']);
        $this->assertSame([null, null, null, null], array_column($subjectRow['quarters'], 'grade'));
    }

    public function test_shs_card_has_two_quarters_per_subject_and_two_general_averages(): void
    {
        $this->createSecondTerm();
        $student = $this->enrollShsStudent(self::AQUINO, $this->stemSection, [1, 2]);
        $firstTermClass = $this->createShsClass($this->mathTeacher, 1);
        $secondTermClass = $this->createShsClass($this->mathTeacher, 2);
        $this->createSubmittedQuarter($firstTermClass, [['student_id' => $student->id, 'quarterly_grade' => 84]], 1);
        $this->createSubmittedQuarter($firstTermClass, [['student_id' => $student->id, 'quarterly_grade' => 86]], 2);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 90]], 3);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 92]], 4);

        $card = $this->fetchCard($student);

        $this->assertCount(2, $card['subjects']);
        $this->assertSame([1, 2], array_column($card['subjects'][0]['quarters'], 'quarter'));
        $this->assertSame([3, 4], array_column($card['subjects'][1]['quarters'], 'quarter'));
        $this->assertSame([85, 91], array_column($card['subjects'], 'final_grade'));
        $this->assertSame([1, 2], array_column($card['general_averages'], 'term_number'));
        $this->assertSame(['85.00', '91.00'], array_column($card['general_averages'], 'average'));
    }

    public function test_shs_second_term_row_uses_the_class_of_another_section(): void
    {
        $this->createSecondTerm();
        $otherSection = $this->createSection(['name' => 'STEM-B', 'grade_level_id' => $this->findGradeLevelId(self::GRADE_11), 'program_id' => $this->stemProgram->id]);
        $student = $this->enrollShsStudent(self::AQUINO, $this->stemSection, [1]);
        $this->enrollShsStudent(self::AQUINO, $otherSection, [2], $student);
        $this->createShsClass($this->mathTeacher, 1);
        $secondTermClass = $this->createShsClass($this->mathTeacher, 2, $otherSection);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 90]], 3);
        $this->createSubmittedQuarter($secondTermClass, [['student_id' => $student->id, 'quarterly_grade' => 92]], 4);

        $card = $this->fetchCard($student);

        $this->assertSame([null, null], array_column($card['subjects'][0]['quarters'], 'grade'));
        $this->assertSame([90, 92], array_column($card['subjects'][1]['quarters'], 'grade'));
        $this->assertSame(91, $card['subjects'][1]['final_grade']);
    }

    public function test_moved_student_has_quarter_one_from_the_old_section_and_quarter_two_from_the_new_one(): void
    {
        $newSection = $this->createSection(['name' => self::MABINI]);
        $student = $this->enrollInRizal(self::AQUINO, Enrollment::STATUS_MOVED);
        $this->enrollExistingStudent($student, ['section_id' => $newSection->id]);
        $oldClass = $this->createClassForFixtures();
        $newClass = $this->createScheduledClass(['section_id' => $newSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id]);
        $this->createSubmittedQuarter($oldClass, [['student_id' => $student->id, 'quarterly_grade' => 80]], 1);
        $this->createSubmittedQuarter($newClass, [['student_id' => $student->id, 'quarterly_grade' => 85]], 2);

        $this->assertSame([80, 85, null, null], array_column($this->fetchCard($student)['subjects'][0]['quarters'], 'grade'));
    }

    public function test_latest_section_wins_for_the_same_quarter(): void
    {
        $newSection = $this->createSection(['name' => self::MABINI]);
        $student = $this->enrollInRizal(self::AQUINO, Enrollment::STATUS_MOVED);
        $this->enrollExistingStudent($student, ['section_id' => $newSection->id]);
        $oldClass = $this->createClassForFixtures();
        $newClass = $this->createScheduledClass(['section_id' => $newSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id]);
        $this->createSubmittedQuarter($newClass, [['student_id' => $student->id, 'quarterly_grade' => 85]], 1);
        $this->createSubmittedQuarter($oldClass, [['student_id' => $student->id, 'quarterly_grade' => 80]], 1);

        $this->assertSame(85, $this->fetchCard($student)['subjects'][0]['quarters'][0]['grade']);
    }

    public function test_student_without_an_enrollment_in_the_year_returns_422(): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->adminToken)->getJson($this->reportCardUrl($student, $this->schoolYear->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => ShowReportCardRequest::NO_ENROLLMENT_MESSAGE]);
    }

    public function test_trimester_school_year_returns_422(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);
        SchoolYear::whereKey($this->schoolYear->id)->update(['term_type' => SchoolYear::TERM_TYPE_TRIMESTER]);

        $this->withToken($this->adminToken)->getJson($this->reportCardUrl($student, $this->schoolYear->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => ShowClassQuarterRequest::TRIMESTER_MESSAGE]);
    }

    public function test_missing_school_year_id_returns_422(): void
    {
        $student = $this->enrollInRizal(self::AQUINO);

        $this->withToken($this->adminToken)->getJson('/api/mis/students/'.$student->id.'/report-card')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_missing_student_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson('/api/mis/students/9999/report-card?school_year_id='.$this->schoolYear->id)->assertNotFound();
    }

    public function test_section_cards_hold_every_listed_student_in_order_with_blanks(): void
    {
        $this->createClassForFixtures();
        $this->enrollInRizal(self::BONIFACIO);
        $this->enrollInRizal(self::AQUINO);

        $cardsResponse = $this->withToken($this->adminToken)->getJson($this->sectionReportCardsUrl($this->rizalSection))->assertOk();

        $this->assertSame(['section', 'school_year', 'report_cards'], array_keys($cardsResponse->json()));
        $this->assertSame(['id' => $this->rizalSection->id, 'name' => self::RIZAL], $cardsResponse->json('section'));
        $this->assertSame([self::AQUINO, self::BONIFACIO], array_column(array_column($cardsResponse->json('report_cards'), 'student'), 'last_name'));
        $this->assertSame([null, null, null, null], array_column($cardsResponse->json('report_cards.0.subjects.0.quarters'), 'grade'));
    }

    public function test_section_cards_exclude_students_who_left(): void
    {
        $this->enrollInRizal(self::AQUINO);
        $this->enrollInRizal(self::BONIFACIO, Enrollment::STATUS_DROPPED);

        $this->withToken($this->adminToken)->getJson($this->sectionReportCardsUrl($this->rizalSection))
            ->assertOk()
            ->assertJsonCount(1, 'report_cards');
    }

    public function test_college_section_returns_422(): void
    {
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id]);

        $this->withToken($this->adminToken)->getJson($this->sectionReportCardsUrl($collegeSection))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => ShowSectionReportCardsRequest::STAGE_MESSAGE]);
    }

    public function test_missing_section_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson('/api/mis/sections/9999/report-cards')->assertNotFound();
    }

    /**
     * @param  list<int>  $termNumbers
     */
    private function enrollShsStudent(string $lastName, Section $section, array $termNumbers, ?Student $student = null): Student
    {
        $student ??= Student::factory()->create(['last_name' => $lastName]);

        foreach ($termNumbers as $termNumber) {
            $termId = Term::where('school_year_id', $this->schoolYear->id)->where('term_number', $termNumber)->value('id');
            $this->enrollExistingStudent($student, ['section_id' => $section->id, 'term_id' => $termId]);
        }

        return $student;
    }
}
