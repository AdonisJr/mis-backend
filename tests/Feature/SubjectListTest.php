<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectListTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private const PAGE_SIZE = 15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'type' => ['type=college_major'],
            'status' => ['status=archived'],
        ];
    }

    public function test_list_paginates_sixteen_subjects_ordered_by_code(): void
    {
        foreach (range(1, 16) as $subjectNumber) {
            Subject::factory()->create(['code' => sprintf('PG%02d', $subjectNumber)]);
        }

        $firstPage = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL)->assertOk();
        $secondPage = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?page=2')->assertOk();

        $this->assertCount(self::PAGE_SIZE, $firstPage->json('subjects'));
        $this->assertSame('PG01', $firstPage->json('subjects.0.code'));
        $this->assertSame(['PG16'], array_column($secondPage->json('subjects'), 'code'));
        $firstPage->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16]);
    }

    public function test_list_searches_code_and_name(): void
    {
        Subject::factory()->create(['code' => self::MATH_7, 'name' => 'Mathematics 7']);
        Subject::factory()->create(['code' => self::ENGLISH_7, 'name' => 'English 7']);

        $codeResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?search=math7')->assertOk();
        $nameResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?search=english')->assertOk();

        $this->assertSame([self::MATH_7], array_column($codeResponse->json('subjects'), 'code'));
        $this->assertSame([self::ENGLISH_7], array_column($nameResponse->json('subjects'), 'code'));
    }

    public function test_list_filters_by_type(): void
    {
        $this->createSubject(self::MATH_7);
        $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);

        $filteredResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?type='.Subject::TYPE_SHS_CORE)->assertOk();

        $this->assertSame([self::GEN_MATH], array_column($filteredResponse->json('subjects'), 'code'));
    }

    public function test_list_filters_by_status(): void
    {
        $this->createSubject(self::MATH_7);
        Subject::factory()->inactive()->create(['code' => self::ENGLISH_7]);

        $activeResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?status=active')->assertOk();
        $inactiveResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL.'?status=inactive')->assertOk();

        $this->assertSame([self::MATH_7], array_column($activeResponse->json('subjects'), 'code'));
        $this->assertSame([self::ENGLISH_7], array_column($inactiveResponse->json('subjects'), 'code'));
    }

    public function test_list_includes_inactive_subjects_by_default(): void
    {
        $this->createSubject(self::MATH_7);
        Subject::factory()->inactive()->create(['code' => self::ENGLISH_7]);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL)->assertOk();

        $this->assertSame([self::ENGLISH_7, self::MATH_7], array_column($listResponse->json('subjects'), 'code'));
    }

    public function test_active_list_is_unpaginated_and_skips_inactive_subjects(): void
    {
        Subject::factory()->count(20)->create();
        Subject::factory()->inactive()->create();

        $activeResponse = $this->withToken($this->adminToken)->getJson(self::ACTIVE_SUBJECTS_URL)->assertOk();

        $this->assertCount(20, $activeResponse->json('subjects'));
        $this->assertSame(['subjects'], array_keys($activeResponse->json()));
        $this->assertEqualsCanonicalizing(self::SUBJECT_KEYS, array_keys($activeResponse->json('subjects.0')));
    }

    #[DataProvider('provideInvalidFilters')]
    public function test_list_rejects_invalid_filters(string $queryString): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::SUBJECTS_URL.'?'.$queryString)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([strtok($queryString, '=')]);
    }
}
