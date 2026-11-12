<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StudentListTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENTS_URL = '/api/mis/students';

    private const TOKEN_NAME = 'test';

    private const PAGE_SIZE = 15;

    private const STUDENT_KEYS = [
        'id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender',
        'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid', 'current_enrollment',
        'created_at', 'updated_at',
    ];

    private const ANDREA = [
        'lrn' => '136512340001',
        'first_name' => 'Andrea',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
    ];

    private const MIGUEL = [
        'lrn' => '136512340002',
        'first_name' => 'Miguel',
        'middle_name' => null,
        'last_name' => 'Reyes',
    ];

    private const CARLO = [
        'lrn' => '136512340003',
        'first_name' => 'Carlo',
        'middle_name' => 'Garcia',
        'last_name' => 'Dela Rosa',
    ];

    public function test_list_returns_first_page_with_meta(): void
    {
        Student::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchStudentList()
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'students')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('links');
    }

    public function test_list_returns_second_page(): void
    {
        $oldestStudent = Student::factory()->create(['created_at' => now()->subDay()]);
        Student::factory()->count(self::PAGE_SIZE)->create();

        $this->fetchStudentList(['page' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $oldestStudent->id)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_ignores_per_page_param(): void
    {
        Student::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchStudentList(['per_page' => 100])
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'students')
            ->assertJsonPath('meta.per_page', self::PAGE_SIZE);
    }

    public function test_list_page_past_the_end_returns_empty_students(): void
    {
        Student::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchStudentList(['page' => 999])
            ->assertOk()
            ->assertExactJson([
                'students' => [],
                'meta' => ['current_page' => 999, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16],
            ]);
    }

    public function test_list_invalid_page_falls_back_to_first(): void
    {
        Student::factory()->count(self::PAGE_SIZE + 1)->create();

        foreach (['abc', '0', '-1'] as $invalidPage) {
            $this->fetchStudentList(['page' => $invalidPage])
                ->assertOk()
                ->assertJsonCount(self::PAGE_SIZE, 'students')
                ->assertJsonPath('meta.current_page', 1);
        }
    }

    public function test_list_returns_empty_students_when_none(): void
    {
        $this->fetchStudentList()
            ->assertOk()
            ->assertExactJson([
                'students' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 0],
            ]);
    }

    public function test_list_returns_exact_student_shape(): void
    {
        $student = Student::factory()->withRfid()->create(['birth_date' => '2010-05-14'])->fresh();

        $listResponse = $this->fetchStudentList()
            ->assertOk()
            ->assertExactJson([
                'students' => [[
                    'id' => $student->id,
                    'lrn' => $student->lrn,
                    'first_name' => $student->first_name,
                    'middle_name' => $student->middle_name,
                    'last_name' => $student->last_name,
                    'birth_date' => '2010-05-14',
                    'gender' => $student->gender,
                    'address' => $student->address,
                    'contact' => $student->contact,
                    'guardian_name' => $student->guardian_name,
                    'guardian_relationship' => $student->guardian_relationship,
                    'guardian_contact' => $student->guardian_contact,
                    'rfid' => $student->rfid,
                    'current_enrollment' => null,
                    'created_at' => $student->created_at->toJSON(),
                    'updated_at' => $student->updated_at->toJSON(),
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 1],
            ]);

        $this->assertSame(self::STUDENT_KEYS, array_keys($listResponse->json('students.0')));
        $this->assertNotNull($student->rfid);
    }

    public function test_list_returns_null_rfid_for_student_without_card(): void
    {
        Student::factory()->create();

        $listResponse = $this->fetchStudentList()->assertOk();

        $this->assertArrayHasKey('rfid', $listResponse->json('students.0'));
        $this->assertNull($listResponse->json('students.0.rfid'));
    }

    public function test_list_orders_newest_first(): void
    {
        $twoDaysOldStudent = Student::factory()->create(['created_at' => now()->subDays(2)]);
        $newestStudent = Student::factory()->create(['created_at' => now()]);
        $oneDayOldStudent = Student::factory()->create(['created_at' => now()->subDay()]);
        $threeDaysAgo = now()->subDays(3);
        $firstThreeDaysOldStudent = Student::factory()->create(['created_at' => $threeDaysAgo]);
        $secondThreeDaysOldStudent = Student::factory()->create(['created_at' => $threeDaysAgo]);

        $this->assertSame([
            $newestStudent->id,
            $oneDayOldStudent->id,
            $twoDaysOldStudent->id,
            $secondThreeDaysOldStudent->id,
            $firstThreeDaysOldStudent->id,
        ], $this->fetchStudentIds());
    }

    public function test_search_by_full_lrn(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['search' => '136512340001']));
    }

    public function test_search_by_partial_lrn(): void
    {
        Student::factory()->create(self::ANDREA);
        $miguel = Student::factory()->create(self::MIGUEL);

        $this->assertSame([$miguel->id], $this->fetchStudentIds(['search' => '0002']));
    }

    public function test_search_by_name_is_case_insensitive_and_multi_word(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        $miguel = Student::factory()->create(self::MIGUEL);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['search' => 'andrea dela']));
        $this->assertSame([$andrea->id], $this->fetchStudentIds(['search' => 'ANDREA']));
        $this->assertSame([$andrea->id], $this->fetchStudentIds(['search' => 'santos']));
        $this->assertSame([$miguel->id], $this->fetchStudentIds(['search' => 'reyes']));
        $this->assertSame([], $this->fetchStudentIds(['search' => 'andrea reyes']));
    }

    public function test_search_trims_and_collapses_whitespace(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['search' => '  andrea   dela  ']));
    }

    public function test_empty_search_returns_all(): void
    {
        Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);

        foreach (['', '  '] as $blankSearch) {
            $this->assertCount(2, $this->fetchStudentIds(['search' => $blankSearch]));
        }
    }

    public function test_filter_by_grade_level(): void
    {
        [$jhsSection, $collegeSection] = $this->createActiveYearSections();
        $andrea = $this->createEnrolledStudent(self::ANDREA, $jhsSection);
        $miguel = $this->createEnrolledStudent(self::MIGUEL, $collegeSection);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['grade_level_id' => $jhsSection->grade_level_id]));
        $this->assertSame([$miguel->id], $this->fetchStudentIds(['grade_level_id' => $collegeSection->grade_level_id]));
    }

    public function test_search_and_filters_combine(): void
    {
        [$jhsSection, $collegeSection] = $this->createActiveYearSections();
        $andrea = $this->createEnrolledStudent(self::ANDREA, $jhsSection);
        $this->createEnrolledStudent(self::MIGUEL, $collegeSection);
        $this->createEnrolledStudent(self::CARLO, $collegeSection);

        $this->assertCount(2, $this->fetchStudentIds(['search' => 'dela']));

        $this->fetchStudentList(['search' => 'dela', 'grade_level_id' => $jhsSection->grade_level_id])
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $andrea->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_filter_with_no_match_returns_empty_page(): void
    {
        [$jhsSection] = $this->createActiveYearSections();
        $this->createEnrolledStudent(self::ANDREA, $jhsSection);
        Student::factory()->create(self::MIGUEL);

        $this->fetchStudentList(['grade_level_id' => GradeLevel::where('name', 'Grade 12')->value('id')])
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_shows_current_enrollment_of_active_term(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create();
        $activeTerm = Term::factory()->active()->create(['school_year_id' => $schoolYear->id]);
        $otherTerm = $this->createSecondTerm($schoolYear);
        $shsSection = Section::factory()->create(['school_year_id' => $schoolYear->id]);
        $currentStudent = Student::factory()->create(self::ANDREA);
        $otherTermStudent = Student::factory()->create(self::MIGUEL);
        $currentEnrollment = Enrollment::factory()->create(['student_id' => $currentStudent->id, 'section_id' => $shsSection->id, 'term_id' => $activeTerm->id]);
        Enrollment::factory()->create(['student_id' => $otherTermStudent->id, 'section_id' => $shsSection->id, 'term_id' => $otherTerm->id]);

        $currentEnrollments = $this->fetchCurrentEnrollments();

        $this->assertSame([
            'id' => $currentEnrollment->id,
            'section_id' => $shsSection->id,
            'term_id' => $activeTerm->id,
            'section' => ['id' => $shsSection->id, 'name' => $shsSection->name],
            'grade_level' => ['id' => $shsSection->grade_level_id, 'name' => $shsSection->gradeLevel->name, 'stage' => GradeLevel::STAGE_SHS],
        ], $currentEnrollments[$currentStudent->id]);
        $this->assertNull($currentEnrollments[$otherTermStudent->id]);
    }

    public function test_current_enrollment_is_whole_year_record_for_jhs(): void
    {
        [$jhsSection] = $this->createActiveYearSections();
        Term::factory()->active()->create(['school_year_id' => $jhsSection->school_year_id]);
        $andrea = $this->createEnrolledStudent(self::ANDREA, $jhsSection);

        $currentEnrollment = $this->fetchCurrentEnrollments()[$andrea->id];

        $this->assertSame($jhsSection->id, $currentEnrollment['section_id']);
        $this->assertNull($currentEnrollment['term_id']);
    }

    public function test_current_enrollment_uses_enrolled_record_of_year_without_active_term(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create();
        $firstTerm = Term::factory()->create(['school_year_id' => $schoolYear->id]);
        $secondTerm = $this->createSecondTerm($schoolYear);
        $firstSection = Section::factory()->create(['school_year_id' => $schoolYear->id]);
        $secondSection = Section::factory()->create(['school_year_id' => $schoolYear->id]);
        $andrea = Student::factory()->create(self::ANDREA);
        Enrollment::factory()->create(['student_id' => $andrea->id, 'section_id' => $firstSection->id, 'term_id' => $firstTerm->id]);
        $newerEnrollment = Enrollment::factory()->create(['student_id' => $andrea->id, 'section_id' => $secondSection->id, 'term_id' => $secondTerm->id]);
        Enrollment::factory()->closed(Enrollment::STATUS_DROPPED)->create(['student_id' => $andrea->id, 'section_id' => $firstSection->id, 'term_id' => $secondTerm->id]);

        $this->assertSame($newerEnrollment->id, $this->fetchCurrentEnrollments()[$andrea->id]['id']);
    }

    public function test_current_enrollment_is_null_without_active_school_year(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        Enrollment::factory()->create(['student_id' => $andrea->id]);

        $this->assertNull($this->fetchCurrentEnrollments()[$andrea->id]);
    }

    public function test_grade_level_filter_ignores_closed_and_past_records(): void
    {
        [$activeSection] = $this->createActiveYearSections();
        $pastSection = Section::factory()->jhs()->create();
        $closedStudent = Student::factory()->create(self::ANDREA);
        $pastStudent = Student::factory()->create(self::MIGUEL);
        $currentStudent = $this->createEnrolledStudent(self::CARLO, $activeSection);
        Enrollment::factory()->closed(Enrollment::STATUS_DROPPED)->create(['student_id' => $closedStudent->id, 'section_id' => $activeSection->id]);
        Enrollment::factory()->create(['student_id' => $pastStudent->id, 'section_id' => $pastSection->id]);

        $this->assertSame([$currentStudent->id], $this->fetchStudentIds(['grade_level_id' => $activeSection->grade_level_id]));
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        [$jhsSection, $collegeSection] = $this->createActiveYearSections();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $this->createEnrolledStudent(self::ANDREA, $jhsSection);

        DB::enableQueryLog();
        $this->withToken($adminToken)->getJson(self::STUDENTS_URL)->assertOk()->assertJsonCount(1, 'students');
        $singleRowCount = $this->countNonTokenQueries();

        $this->createEnrolledStudent(self::MIGUEL, $collegeSection);
        $this->createEnrolledStudent(self::CARLO, $jhsSection);
        Student::factory()->count(2)->create();
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson(self::STUDENTS_URL)->assertOk()->assertJsonCount(5, 'students');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    public function test_search_with_sql_metacharacters_is_safe(): void
    {
        Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);

        $this->assertSame([], $this->fetchStudentIds(['search' => "' OR 1=1 --"]));
        $this->assertCount(2, $this->fetchStudentIds(['search' => '%']));
        $this->assertCount(2, $this->fetchStudentIds(['search' => '_']));
        $this->assertDatabaseCount('students', 2);
    }

    public function test_list_rejects_invalid_query_params(): void
    {
        foreach (['search', 'grade_level_id'] as $queryKey) {
            $this->fetchStudentList([$queryKey => ['x']])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$queryKey]);
        }

        $this->fetchStudentList(['search' => Str::repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);

        $this->fetchStudentList(['grade_level_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id']);
        $this->fetchStudentList(['grade_level' => 'Grade 10', 'section' => 'Rizal'])->assertOk();
    }

    /**
     * @return array{0: Section, 1: Section}
     */
    private function createActiveYearSections(): array
    {
        $schoolYear = SchoolYear::factory()->active()->create();

        return [
            Section::factory()->jhs()->create(['school_year_id' => $schoolYear->id]),
            Section::factory()->college()->create(['school_year_id' => $schoolYear->id]),
        ];
    }

    private function createSecondTerm(SchoolYear $schoolYear): Term
    {
        return Term::factory()->create([
            'school_year_id' => $schoolYear->id,
            'term_number' => 2,
            'start_date' => $schoolYear->start_date->addDays(200)->toDateString(),
            'end_date' => $schoolYear->start_date->addDays(300)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $studentFields
     */
    private function createEnrolledStudent(array $studentFields, Section $section): Student
    {
        $student = Student::factory()->create($studentFields);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => $section->id]);

        return $student;
    }

    /**
     * @return array<int, array<string, mixed>|null>
     */
    private function fetchCurrentEnrollments(): array
    {
        return collect($this->fetchStudentList()->assertOk()->json('students'))->pluck('current_enrollment', 'id')->all();
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchStudentList(array $queryParams = []): TestResponse
    {
        $queryString = $queryParams === [] ? '' : '?'.http_build_query($queryParams);

        return $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::STUDENTS_URL.$queryString);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return list<int>
     */
    private function fetchStudentIds(array $queryParams = []): array
    {
        return collect($this->fetchStudentList($queryParams)->assertOk()->json('students'))->pluck('id')->all();
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
