<?php

namespace Tests\Feature;

use App\Http\Requests\ShowClassQuarterRequest;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class GradingQuarterRulesTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideJhsQuarters(): array
    {
        return ['quarter 1' => [1], 'quarter 2' => [2], 'quarter 3' => [3], 'quarter 4' => [4]];
    }

    #[DataProvider('provideJhsQuarters')]
    public function test_jhs_class_reads_every_quarter_of_the_year(int $quarter): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($schoolClass, $quarter))
            ->assertOk()
            ->assertJsonPath('class_record.quarter', $quarter)
            ->assertJsonPath('class_record.quarters', [1, 2, 3, 4]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideOutOfRangeJhsQuarters(): array
    {
        return ['quarter 0' => [0], 'quarter 5' => [5]];
    }

    #[DataProvider('provideOutOfRangeJhsQuarters')]
    public function test_jhs_class_refuses_a_quarter_outside_one_to_four(int $quarter): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($schoolClass, $quarter))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => sprintf(ShowClassQuarterRequest::QUARTER_RANGE_MESSAGE, '1, 2, 3, 4')]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideFirstTermQuarters(): array
    {
        return ['quarter 1' => [1], 'quarter 2' => [2]];
    }

    #[DataProvider('provideFirstTermQuarters')]
    public function test_shs_term_one_class_reads_quarters_one_and_two(int $quarter): void
    {
        $this->createTerm(1);
        $shsClass = $this->createShsClass($this->mathTeacher, 1);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($shsClass, $quarter))->assertOk();
    }

    public function test_shs_term_one_class_refuses_quarter_three(): void
    {
        $shsClass = $this->createShsClass($this->mathTeacher, 1);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($shsClass, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => sprintf(ShowClassQuarterRequest::QUARTER_RANGE_MESSAGE, '1, 2')]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideSecondTermQuarters(): array
    {
        return ['quarter 3' => [3], 'quarter 4' => [4]];
    }

    #[DataProvider('provideSecondTermQuarters')]
    public function test_shs_term_two_class_reads_quarters_three_and_four(int $quarter): void
    {
        $this->createTerm(2);
        $shsClass = $this->createShsClass($this->mathTeacher, 2);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($shsClass, $quarter))->assertOk();
    }

    public function test_shs_term_two_class_refuses_quarter_two(): void
    {
        $shsClass = $this->createShsClass($this->mathTeacher, 2);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($shsClass, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => sprintf(ShowClassQuarterRequest::QUARTER_RANGE_MESSAGE, '3, 4')]);
    }

    public function test_trimester_school_year_refuses_a_read(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        SchoolYear::whereKey($this->schoolYear->id)->update(['term_type' => SchoolYear::TERM_TYPE_TRIMESTER]);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($schoolClass, 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => ShowClassQuarterRequest::TRIMESTER_MESSAGE]);
    }

    public function test_trimester_school_year_refuses_an_activity_create(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);
        SchoolYear::whereKey($this->schoolYear->id)->update(['term_type' => SchoolYear::TERM_TYPE_TRIMESTER]);

        $this->withToken($this->createTeacherOfClassToken($schoolClass))->postJson($this->activitiesUrl($schoolClass, 1), $this->buildActivityPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => ShowClassQuarterRequest::TRIMESTER_MESSAGE]);
    }

    public function test_non_numeric_quarter_returns_404(): void
    {
        $schoolClass = $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->adminToken)->getJson(self::CLASSES_URL.'/'.$schoolClass->id.'/quarters/abc/record')->assertNotFound();
    }

    public function test_shs_term_one_record_lists_quarters_one_and_two(): void
    {
        $this->createTerm(1);
        $shsClass = $this->createShsClass($this->mathTeacher, 1);

        $this->withToken($this->adminToken)->getJson($this->recordUrl($shsClass, 1))
            ->assertOk()
            ->assertJsonPath('class_record.quarters', [1, 2]);
    }
}
