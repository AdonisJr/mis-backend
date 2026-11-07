<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender', 'grade_level', 'section',
        'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid',
        'created_at', 'updated_at',
    ];

    private const ANDREA = [
        'lrn' => '136512340001',
        'first_name' => 'Andrea',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'grade_level' => 'Grade 10',
        'section' => 'Rizal',
    ];

    private const MIGUEL = [
        'lrn' => '136512340002',
        'first_name' => 'Miguel',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'grade_level' => '1st Year College',
        'section' => 'BSIT',
    ];

    private const CARLO = [
        'lrn' => '136512340003',
        'first_name' => 'Carlo',
        'middle_name' => 'Garcia',
        'last_name' => 'Dela Rosa',
        'grade_level' => 'Grade 10',
        'section' => 'Bonifacio',
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
                    'grade_level' => $student->grade_level,
                    'section' => $student->section,
                    'address' => $student->address,
                    'contact' => $student->contact,
                    'guardian_name' => $student->guardian_name,
                    'guardian_relationship' => $student->guardian_relationship,
                    'guardian_contact' => $student->guardian_contact,
                    'rfid' => $student->rfid,
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
        $andrea = Student::factory()->create(self::ANDREA);
        $miguel = Student::factory()->create(self::MIGUEL);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['grade_level' => 'Grade 10']));
        $this->assertSame([$miguel->id], $this->fetchStudentIds(['grade_level' => '1st Year College']));
    }

    public function test_filter_by_section(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        $miguel = Student::factory()->create(self::MIGUEL);

        $this->assertSame([$andrea->id], $this->fetchStudentIds(['section' => 'Rizal']));
        $this->assertSame([$miguel->id], $this->fetchStudentIds(['section' => 'BSIT']));
    }

    public function test_search_and_filters_combine(): void
    {
        $andrea = Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);
        $carlo = Student::factory()->create(self::CARLO);

        $this->assertEqualsCanonicalizing([$andrea->id, $carlo->id], $this->fetchStudentIds(['search' => 'dela']));

        $this->fetchStudentList(['search' => 'dela', 'grade_level' => 'Grade 10', 'section' => 'Rizal'])
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $andrea->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_filter_with_no_match_returns_empty_page(): void
    {
        Student::factory()->create(self::ANDREA);
        Student::factory()->create(self::MIGUEL);

        $this->fetchStudentList(['grade_level' => 'Grade 12'])
            ->assertOk()
            ->assertJsonPath('students', [])
            ->assertJsonPath('meta.total', 0);
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
        foreach (['search', 'grade_level', 'section'] as $queryKey) {
            $this->fetchStudentList([$queryKey => ['x']])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$queryKey]);
        }

        $this->fetchStudentList(['search' => Str::repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
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
