<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithAssessments;
use Tests\TestCase;

class AssessmentListTest extends TestCase
{
    use InteractsWithAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_list_requires_school_year(): void
    {
        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL)->assertUnprocessable()->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_returns_current_assessments_of_year_by_student_name(): void
    {
        $cruzBen = $this->createAssessmentRow(Student::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz']));
        $abadZed = $this->createAssessmentRow(Student::factory()->create(['first_name' => 'Zed', 'last_name' => 'Abad']));
        $cruzAna = $this->createAssessmentRow(Student::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']));
        $this->createAssessmentRow(Student::factory()->create(), ['status' => Assessment::STATUS_SUPERSEDED]);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        Assessment::factory()->create(['school_year_id' => $otherYear->id]);

        $listResponse = $this->fetchAssessmentList()->assertOk()->assertJsonCount(3, 'assessments')->assertJsonPath('meta.total', 3);

        $this->assertSame([$abadZed->id, $cruzAna->id, $cruzBen->id], array_column($listResponse->json('assessments'), 'id'));
        $this->assertSame(self::ASSESSMENT_ROW_KEYS, array_keys($listResponse->json('assessments.0')));
        $this->assertSame(['id', 'lrn', 'first_name', 'middle_name', 'last_name'], array_keys($listResponse->json('assessments.0.student')));
    }

    public function test_list_searches_student_name_and_lrn(): void
    {
        $juan = $this->createAssessmentRow(Student::factory()->create(['lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']));
        $this->createAssessmentRow(Student::factory()->create());

        $this->fetchAssessmentList('&search=dela+JUAN')->assertOk()->assertJsonCount(1, 'assessments')->assertJsonPath('assessments.0.id', $juan->id);
        $this->fetchAssessmentList('&search='.self::FIXED_LRN)->assertOk()->assertJsonCount(1, 'assessments')->assertJsonPath('assessments.0.id', $juan->id);
        $this->fetchAssessmentList('&search=nobody')->assertOk()->assertJsonCount(0, 'assessments');
    }

    public function test_list_filters_by_grade_level(): void
    {
        $this->createAssessmentRow(Student::factory()->create());
        $gradeEight = $this->createAssessmentRow(Student::factory()->create(), ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]);

        $this->fetchAssessmentList('&grade_level_id='.$this->findGradeLevelId(self::GRADE_8))
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.id', $gradeEight->id)
            ->assertJsonPath('assessments.0.grade_level.name', self::GRADE_8);
    }

    public function test_list_filters_by_payment_plan(): void
    {
        $this->createAssessmentRow(Student::factory()->create());
        $otherPlan = $this->createMonthlyPlanRow();
        $planRow = $this->createAssessmentRow(Student::factory()->create(), ['payment_plan_id' => $otherPlan->id, 'plan_name' => $otherPlan->name]);

        $this->fetchAssessmentList('&payment_plan_id='.$otherPlan->id)
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.id', $planRow->id)
            ->assertJsonPath('assessments.0.plan_name', self::MONTHLY_PLAN);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        foreach (['grade_level_id' => ['05', self::MISSING_ID], 'payment_plan_id' => ['05', self::MISSING_ID]] as $filterKey => $invalidIds) {
            foreach ($invalidIds as $invalidId) {
                $this->fetchAssessmentList('&'.$filterKey.'='.$invalidId)->assertUnprocessable()->assertJsonValidationErrors([$filterKey]);
            }
        }

        $this->fetchAssessmentList('&search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'?school_year_id='.self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        foreach (range(1, 16) as $rowNumber) {
            $this->createAssessmentRow(Student::factory()->create());
        }

        $this->fetchAssessmentList()->assertOk()->assertJsonCount(15, 'assessments')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 15);
        $this->fetchAssessmentList('&page=2')->assertOk()->assertJsonCount(1, 'assessments')->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $this->createAssessmentRow(Student::factory()->create());

        DB::enableQueryLog();
        $this->fetchAssessmentList()->assertOk()->assertJsonCount(1, 'assessments');
        $singleRowCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 3) as $rowNumber) {
            $this->createAssessmentRow(Student::factory()->create());
        }

        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchAssessmentList()->assertOk()->assertJsonCount(4, 'assessments');

        $this->assertSame($singleRowCount, $this->countQueriesWithoutTokens());
    }

    public function test_candidates_require_school_year(): void
    {
        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/candidates')->assertUnprocessable()->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_candidates_list_eligible_students_without_current_assessment(): void
    {
        $enrolled = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, studentFields: ['last_name' => 'Cruz', 'first_name' => 'Ana']);
        $completed = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_COMPLETED, ['last_name' => 'Abad', 'first_name' => 'Ben']);
        $seniorHigh = $this->createEnrolledStudent(GradeLevel::STAGE_SHS, studentFields: ['last_name' => 'Cruz', 'first_name' => 'Carl']);
        $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_DROPPED);
        $assessed = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->createAssessmentRow($assessed);
        $resupersededOnly = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, studentFields: ['last_name' => 'Zamora', 'first_name' => 'Dan']);
        $this->createAssessmentRow($resupersededOnly, ['status' => Assessment::STATUS_SUPERSEDED]);
        $this->createCollegeStudent();
        $otherYearStudent = Student::factory()->create();
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        Enrollment::factory()->create(['student_id' => $otherYearStudent->id, 'section_id' => Section::factory()->jhs()->create(['school_year_id' => $otherYear->id])->id]);

        $candidateResponse = $this->fetchCandidateList()->assertOk();

        $this->assertSame([$completed->id, $enrolled->id, $seniorHigh->id, $resupersededOnly->id], array_column($candidateResponse->json('students'), 'id'));
        $this->assertSame(self::CANDIDATE_KEYS, array_keys($candidateResponse->json('students.0')));
        $candidateResponse->assertJsonPath('meta.total', 4);
    }

    public function test_candidates_search_and_paginate(): void
    {
        $juan = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, studentFields: ['lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $this->fetchCandidateList('&search=dela+juan')->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.id', $juan->id);
        $this->fetchCandidateList('&search='.self::FIXED_LRN)->assertOk()->assertJsonCount(1, 'students');
        $this->fetchCandidateList('&search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);

        foreach (range(1, 15) as $rowNumber) {
            $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        }

        $this->fetchCandidateList()->assertOk()->assertJsonCount(15, 'students')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
        $this->fetchCandidateList('&page=2')->assertOk()->assertJsonCount(1, 'students');
    }

    public function test_candidates_report_grade_level_from_source_enrollment(): void
    {
        $student = Student::factory()->create();
        $this->enrollInJhsGradeLevel($student, self::GRADE_7, Enrollment::STATUS_COMPLETED);
        $this->enrollInJhsGradeLevel($student, self::GRADE_8, Enrollment::STATUS_ENROLLED);
        $seniorHigh = $this->createEnrolledStudent(GradeLevel::STAGE_SHS);
        $programCode = Enrollment::where('student_id', $seniorHigh->id)->first()->section->program->code;

        $candidateResponse = $this->fetchCandidateList()->assertOk();

        $byId = collect($candidateResponse->json('students'))->keyBy('id');
        $this->assertSame(self::GRADE_8, $byId[$student->id]['grade_level']['name']);
        $this->assertNull($byId[$student->id]['program']);
        $this->assertSame(self::GRADE_11, $byId[$seniorHigh->id]['grade_level']['name']);
        $this->assertSame($programCode, $byId[$seniorHigh->id]['program']['code']);
    }

    public function test_candidates_query_count_is_independent_of_rows(): void
    {
        // the first row is senior high too: a page without programs skips the program eager load query
        $this->createSeniorHighStudent();

        DB::enableQueryLog();
        $this->fetchCandidateList()->assertOk()->assertJsonCount(1, 'students');
        $singleRowCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 3) as $rowNumber) {
            $this->createSeniorHighStudent();
        }

        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchCandidateList()->assertOk()->assertJsonCount(4, 'students');

        $this->assertSame($singleRowCount, $this->countQueriesWithoutTokens());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAssessmentRow(Student $student, array $overrides = []): Assessment
    {
        return Assessment::factory()->create([
            'student_id' => $student->id,
            'school_year_id' => $this->schoolYear->id,
            'payment_plan_id' => $this->planForRows()->id,
            ...$overrides,
        ]);
    }

    private function planForRows(): PaymentPlan
    {
        return PaymentPlan::where('school_year_id', $this->schoolYear->id)->where('name', self::CASH_PLAN)->first() ?? $this->createCashPlan();
    }

    private function createMonthlyPlanRow(): PaymentPlan
    {
        return $this->createInstallmentPlan(['2030-08-15']);
    }

    private function createCollegeStudent(): Student
    {
        $student = Student::factory()->create();
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id]);
        Enrollment::factory()->college()->create(['student_id' => $student->id, 'section_id' => $collegeSection->id, 'term_id' => $this->findSharedTermId()]);

        return $student;
    }

    private function createSeniorHighStudent(): Student
    {
        $student = Student::factory()->create();
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => Section::factory()->create(['school_year_id' => $this->schoolYear->id])->id, 'term_id' => $this->findSharedTermId()]);

        return $student;
    }

    private function findSharedTermId(): int
    {
        // one term per number and year: every senior high or college row of a test shares the first term
        return Term::where('school_year_id', $this->schoolYear->id)->value('id') ?? Term::factory()->create(['school_year_id' => $this->schoolYear->id])->id;
    }

    private function enrollInJhsGradeLevel(Student $student, string $gradeLevelName, string $status): void
    {
        $section = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'grade_level_id' => $this->findGradeLevelId($gradeLevelName)]);
        $enrollmentFactory = $status === Enrollment::STATUS_ENROLLED ? Enrollment::factory() : Enrollment::factory()->closed($status);
        $enrollmentFactory->create(['student_id' => $student->id, 'section_id' => $section->id]);
    }

    private function fetchAssessmentList(string $query = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'?school_year_id='.$this->schoolYear->id.$query);
    }

    private function fetchCandidateList(string $query = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/candidates?school_year_id='.$this->schoolYear->id.$query);
    }
}
