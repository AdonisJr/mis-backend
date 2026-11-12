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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnrollmentListTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENTS_URL = '/api/mis/enrollments';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const PAGE_SIZE = 15;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2031 = ['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const FIRST_TERM = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_TERM = ['term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31'];

    private const ENROLLMENT_KEYS = ['id', 'student_id', 'section_id', 'term_id', 'status', 'enrolled_at', 'ended_at', 'remarks', 'created_at', 'updated_at', 'student', 'section', 'term'];

    private const ANDREA = ['lrn' => '136512340001', 'first_name' => 'Andrea', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz'];

    private const MIGUEL = ['lrn' => '136512340002', 'first_name' => 'Miguel', 'middle_name' => null, 'last_name' => 'Bautista'];

    private const BEA = ['lrn' => '136512340003', 'first_name' => 'Bea', 'middle_name' => null, 'last_name' => 'Reyes'];

    private const CARLO = ['lrn' => '136512340004', 'first_name' => 'Carlo', 'middle_name' => null, 'last_name' => 'Abad'];

    private SchoolYear $schoolYear;

    private Term $firstTerm;

    private Term $secondTerm;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->secondTerm = Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->adminToken = $this->createTokenFor(User::factory()->admin()->create());
    }

    public function test_list_requires_school_year_id(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::ENROLLMENTS_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        foreach ([self::MISSING_ID, 'abc', ['1']] as $invalidSchoolYearId) {
            $this->assertRejectedFilter(['school_year_id' => $invalidSchoolYearId], 'school_year_id');
        }
        foreach (['term_id', 'section_id', 'grade_level_id'] as $idFilter) {
            foreach ([self::MISSING_ID, 'abc', '1.5'] as $invalidId) {
                $this->assertRejectedFilter([$idFilter => $invalidId], $idFilter);
            }
        }
        foreach (['unknown', 'ENROLLED', ['enrolled']] as $invalidStatus) {
            $this->assertRejectedFilter(['status' => $invalidStatus], 'status');
        }
        foreach ([['x'], str_repeat('s', 256)] as $invalidSearch) {
            $this->assertRejectedFilter(['search' => $invalidSearch], 'search');
        }
    }

    public function test_list_returns_exact_shape(): void
    {
        $jhsSection = $this->createJhsSection('A');
        $shsSection = $this->createShsSection('STEM 11-A');
        $jhsEnrollment = $this->createEnrollment(self::CARLO, ['section_id' => $jhsSection->id, 'enrolled_at' => '2030-07-01'])->fresh();
        $shsEnrollment = $this->createEnrollment(self::ANDREA, ['section_id' => $shsSection->id, 'term_id' => $this->firstTerm->id, 'enrolled_at' => '2030-06-10', 'remarks' => 'Transferee'])->fresh();

        $listResponse = $this->fetchList([])
            ->assertOk()
            ->assertExactJson([
                'enrollments' => [
                    [
                        ...$this->buildEnrollmentColumns($jhsEnrollment),
                        'student' => ['id' => $jhsEnrollment->student_id, 'lrn' => self::CARLO['lrn'], 'first_name' => 'Carlo', 'middle_name' => null, 'last_name' => 'Abad'],
                        'section' => [
                            'id' => $jhsSection->id,
                            'name' => 'A',
                            'grade_level' => ['id' => $jhsSection->grade_level_id, 'name' => 'Grade 7', 'stage' => GradeLevel::STAGE_JHS],
                            'program' => null,
                        ],
                        'term' => null,
                    ],
                    [
                        ...$this->buildEnrollmentColumns($shsEnrollment),
                        'student' => ['id' => $shsEnrollment->student_id, 'lrn' => self::ANDREA['lrn'], 'first_name' => 'Andrea', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz'],
                        'section' => [
                            'id' => $shsSection->id,
                            'name' => 'STEM 11-A',
                            'grade_level' => ['id' => $shsSection->grade_level_id, 'name' => 'Grade 11', 'stage' => GradeLevel::STAGE_SHS],
                            'program' => ['id' => $shsSection->program_id, 'code' => $shsSection->program->code],
                        ],
                        'term' => ['id' => $this->firstTerm->id, 'term_number' => 1, 'label' => '1st Semester'],
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 2],
            ]);

        foreach ($listResponse->json('enrollments') as $listedEnrollment) {
            $this->assertSame(self::ENROLLMENT_KEYS, array_keys($listedEnrollment));
        }
    }

    public function test_list_paginates_15_with_meta(): void
    {
        Enrollment::factory()->count(self::PAGE_SIZE + 1)->create(['section_id' => $this->createJhsSection('A')->id]);

        $this->fetchList([])
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'enrollments')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16])
            ->assertJsonMissingPath('data');
        $this->fetchList(['page' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('meta.current_page', 2);
        $this->fetchList(['per_page' => 5])->assertOk()->assertJsonPath('meta.per_page', self::PAGE_SIZE);
    }

    public function test_list_orders_by_last_name_first_name_id(): void
    {
        $section = $this->createJhsSection('A');
        $reyesBea = $this->createEnrollment(self::BEA, ['section_id' => $section->id]);
        $abadCarlo = $this->createEnrollment(self::CARLO, ['section_id' => $section->id]);
        $abadCarloClosed = $this->createEnrollment(self::CARLO, ['section_id' => $section->id, 'status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-07-01']);
        $abadAndrea = $this->createEnrollment(['lrn' => '136512340005', 'first_name' => 'Andrea', 'middle_name' => null, 'last_name' => 'Abad'], ['section_id' => $section->id]);
        $bautistaMiguel = $this->createEnrollment(self::MIGUEL, ['section_id' => $section->id]);

        $listedIds = array_column($this->fetchList([])->assertOk()->json('enrollments'), 'id');

        $this->assertSame([$abadAndrea->id, $abadCarlo->id, $abadCarloClosed->id, $bautistaMiguel->id, $reyesBea->id], $listedIds);
    }

    public function test_list_returns_only_the_given_school_year(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherYearSection = Section::factory()->jhs()->create(['school_year_id' => $otherYear->id, 'name' => 'A']);
        $listedEnrollment = $this->createEnrollment(self::ANDREA, ['section_id' => $this->createJhsSection('A')->id]);
        $otherYearEnrollment = $this->createEnrollment(self::MIGUEL, ['section_id' => $otherYearSection->id]);

        $this->assertSame([$listedEnrollment->id], array_column($this->fetchList([])->assertOk()->json('enrollments'), 'id'));
        $this->assertSame([$otherYearEnrollment->id], array_column($this->fetchList(['school_year_id' => $otherYear->id])->assertOk()->json('enrollments'), 'id'));
        $this->fetchList(['school_year_id' => SchoolYear::factory()->create()->id])
            ->assertOk()
            ->assertJsonPath('enrollments', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_term_filter_includes_jhs_whole_year_rows(): void
    {
        $shsSection = $this->createShsSection('STEM 11-A');
        $jhsEnrollment = $this->createEnrollment(self::ANDREA, ['section_id' => $this->createJhsSection('A')->id]);
        $firstTermEnrollment = $this->createEnrollment(self::MIGUEL, ['section_id' => $shsSection->id, 'term_id' => $this->firstTerm->id]);
        $this->createEnrollment(self::BEA, ['section_id' => $shsSection->id, 'term_id' => $this->secondTerm->id]);

        $firstTermIds = array_column($this->fetchList(['term_id' => $this->firstTerm->id])->assertOk()->json('enrollments'), 'id');
        $secondTermIds = array_column($this->fetchList(['term_id' => $this->secondTerm->id])->assertOk()->json('enrollments'), 'student_id');

        $this->assertEqualsCanonicalizing([$jhsEnrollment->id, $firstTermEnrollment->id], $firstTermIds);
        $this->assertCount(2, $secondTermIds);
        $this->assertNotContains($firstTermEnrollment->student_id, $secondTermIds);
        $this->assertCount(3, $this->fetchList([])->assertOk()->json('enrollments'));
    }

    public function test_filter_by_section(): void
    {
        $firstSection = $this->createJhsSection('A');
        $secondSection = $this->createJhsSection('B');
        $firstEnrollment = $this->createEnrollment(self::ANDREA, ['section_id' => $firstSection->id]);
        $this->createEnrollment(self::MIGUEL, ['section_id' => $secondSection->id]);

        $this->assertSame([$firstEnrollment->id], array_column($this->fetchList(['section_id' => $firstSection->id])->assertOk()->json('enrollments'), 'id'));
    }

    public function test_filter_by_grade_level(): void
    {
        $jhsSection = $this->createJhsSection('A');
        $shsSection = $this->createShsSection('STEM 11-A');
        $this->createEnrollment(self::ANDREA, ['section_id' => $jhsSection->id]);
        $shsEnrollment = $this->createEnrollment(self::MIGUEL, ['section_id' => $shsSection->id, 'term_id' => $this->firstTerm->id]);

        $this->assertSame([$shsEnrollment->id], array_column($this->fetchList(['grade_level_id' => $shsSection->grade_level_id])->assertOk()->json('enrollments'), 'id'));
        $this->assertCount(1, $this->fetchList(['grade_level_id' => $jhsSection->grade_level_id])->assertOk()->json('enrollments'));
    }

    public function test_filter_by_status_and_absent_status_returns_all(): void
    {
        $section = $this->createJhsSection('A');
        $this->createEnrollment(self::ANDREA, ['section_id' => $section->id]);
        $droppedEnrollment = $this->createEnrollment(self::MIGUEL, ['section_id' => $section->id, 'status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-07-01']);
        $this->createEnrollment(self::BEA, ['section_id' => $section->id, 'status' => Enrollment::STATUS_MOVED, 'ended_at' => '2030-07-01']);

        $this->assertSame([$droppedEnrollment->id], array_column($this->fetchList(['status' => Enrollment::STATUS_DROPPED])->assertOk()->json('enrollments'), 'id'));
        $this->assertCount(1, $this->fetchList(['status' => Enrollment::STATUS_ENROLLED])->assertOk()->json('enrollments'));
        $this->assertCount(3, $this->fetchList([])->assertOk()->json('enrollments'));
        $this->assertCount(3, $this->fetchList(['status' => ''])->assertOk()->json('enrollments'));
    }

    public function test_search_by_name_and_lrn(): void
    {
        $section = $this->createJhsSection('A');
        $andreaEnrollment = $this->createEnrollment(self::ANDREA, ['section_id' => $section->id]);
        $beaEnrollment = $this->createEnrollment(self::BEA, ['section_id' => $section->id]);
        $this->createEnrollment(self::MIGUEL, ['section_id' => $section->id]);

        $this->assertSame([$andreaEnrollment->id], $this->searchIds('dela'));
        $this->assertSame([$andreaEnrollment->id], $this->searchIds('CRUZ'));
        $this->assertSame([$andreaEnrollment->id], $this->searchIds('santos andrea'));
        $this->assertSame([$beaEnrollment->id], $this->searchIds('reyes bea'));
        $this->assertSame([$beaEnrollment->id], $this->searchIds('0003'));
        $this->assertCount(3, $this->searchIds('1365123400'));
        $this->assertSame([], $this->searchIds('andrea reyes'));
        $this->assertCount(3, $this->searchIds('  '));
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $jhsSection = $this->createJhsSection('A');
        $shsSection = $this->createShsSection('STEM 11-A');
        $this->createEnrollment(self::ANDREA, ['section_id' => $shsSection->id, 'term_id' => $this->firstTerm->id]);

        DB::enableQueryLog();
        $this->fetchList([])->assertOk()->assertJsonCount(1, 'enrollments');
        $singleRowCount = $this->countNonTokenQueries();

        $this->createEnrollment(self::MIGUEL, ['section_id' => $shsSection->id, 'term_id' => $this->firstTerm->id]);
        $this->createEnrollment(self::MIGUEL, ['section_id' => $jhsSection->id]);
        $this->createEnrollment(self::BEA, ['section_id' => $shsSection->id, 'term_id' => $this->secondTerm->id]);
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchList([])->assertOk()->assertJsonCount(4, 'enrollments');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    /**
     * @param  array<string, mixed>  $filterParams
     */
    private function assertRejectedFilter(array $filterParams, string $rejectedField): void
    {
        $this->fetchList($filterParams)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$rejectedField]);
    }

    /**
     * @return list<int>
     */
    private function searchIds(string $search): array
    {
        return array_column($this->fetchList(['search' => $search])->assertOk()->json('enrollments'), 'id');
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchList(array $queryParams): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->getJson(self::ENROLLMENTS_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, ...$queryParams]));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnrollmentColumns(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'section_id' => $enrollment->section_id,
            'term_id' => $enrollment->term_id,
            'status' => $enrollment->status,
            'enrolled_at' => $enrollment->enrolled_at->toDateString(),
            'ended_at' => $enrollment->ended_at?->toDateString(),
            'remarks' => $enrollment->remarks,
            'created_at' => $enrollment->created_at->toJSON(),
            'updated_at' => $enrollment->updated_at->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $studentFields
     * @param  array<string, mixed>  $enrollmentFields
     */
    private function createEnrollment(array $studentFields, array $enrollmentFields): Enrollment
    {
        $student = Student::firstWhere('lrn', $studentFields['lrn']) ?? Student::factory()->create($studentFields);

        return Enrollment::factory()->create([
            'student_id' => $student->id,
            'enrolled_at' => '2030-06-10',
            ...$enrollmentFields,
        ]);
    }

    private function createJhsSection(string $name): Section
    {
        return Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name]);
    }

    private function createShsSection(string $name): Section
    {
        return Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name]);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
