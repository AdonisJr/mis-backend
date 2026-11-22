<?php

namespace Tests\Unit;

use App\Models\GradingActivity;
use App\Support\GradeCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GradeCalculatorTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../Fixtures/grade-computation.json';

    private const TWIN_NOTE = 'Identical copy kept in mis-backend tests/Fixtures/grade-computation.json and beside the mis-frontend grade computation Vitest file; change both together.';

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideQuarterCases(): array
    {
        $quarterCases = [];

        foreach (self::loadFixture()['quarter_cases'] as $quarterCase) {
            $quarterCases[$quarterCase['name']] = [$quarterCase];
        }

        return $quarterCases;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideTransmutations(): array
    {
        $transmutations = [];

        foreach (self::loadFixture()['transmutation'] as $transmutation) {
            $transmutations['initial grade '.$transmutation['initial_grade']] = [$transmutation['initial_grade'], $transmutation['quarterly_grade']];
        }

        return $transmutations;
    }

    /**
     * @return list<array{list<int|null>, int|null}>
     */
    public static function provideFinalGrades(): array
    {
        return array_map(fn (array $finalCase) => [$finalCase['quarterly_grades'], $finalCase['final_grade']], self::loadFixture()['final_grades']);
    }

    /**
     * @return list<array{list<int|null>, string|null, int|null, string|null}>
     */
    public static function provideGeneralAverages(): array
    {
        return array_map(
            fn (array $averageCase) => [$averageCase['final_grades'], $averageCase['average'], $averageCase['rounded_average'], $averageCase['descriptor']],
            self::loadFixture()['general_averages'],
        );
    }

    /**
     * @return list<array{int, string}>
     */
    public static function provideRemarks(): array
    {
        return array_map(fn (array $remarkCase) => [$remarkCase['grade'], $remarkCase['remarks']], self::loadFixture()['remarks']);
    }

    /**
     * @param  array<string, mixed>  $quarterCase
     */
    #[DataProvider('provideQuarterCases')]
    public function test_quarter_grades_match_the_fixture(array $quarterCase): void
    {
        $quarterGrades = GradeCalculator::calculateQuarterGrades(self::sumComponents($quarterCase['activities']), $quarterCase['weights']);

        $this->assertSame($quarterCase['expected'], $quarterGrades);
    }

    #[DataProvider('provideTransmutations')]
    public function test_initial_grade_transmutes_to_the_quarterly_grade(string $initialGrade, int $quarterlyGrade): void
    {
        $this->assertSame($quarterlyGrade, GradeCalculator::transmute(BigDecimal::of($initialGrade)));
    }

    /**
     * @param  list<int|null>  $quarterlyGrades
     */
    #[DataProvider('provideFinalGrades')]
    public function test_final_grade_is_the_rounded_mean_or_null(array $quarterlyGrades, ?int $finalGrade): void
    {
        $this->assertSame($finalGrade, GradeCalculator::calculateFinalGrade($quarterlyGrades));
    }

    /**
     * @param  list<int|null>  $finalGrades
     */
    #[DataProvider('provideGeneralAverages')]
    public function test_general_average_rounds_and_describes(array $finalGrades, ?string $average, ?int $roundedAverage, ?string $descriptor): void
    {
        $generalAverage = GradeCalculator::calculateGeneralAverage($finalGrades);

        $this->assertSame($average, $generalAverage === null ? null : (string) $generalAverage);
        $this->assertSame($roundedAverage, $generalAverage === null ? null : GradeCalculator::roundAverage($generalAverage));
        $this->assertSame($descriptor, $generalAverage === null ? null : GradeCalculator::describeAverage($generalAverage));
    }

    #[DataProvider('provideRemarks')]
    public function test_remarks_follow_the_passing_grade(int $grade, string $remarks): void
    {
        $this->assertSame($remarks, GradeCalculator::deriveRemarks($grade));
    }

    public function test_fixture_twin_note_names_both_repositories(): void
    {
        $this->assertSame(self::TWIN_NOTE, self::loadFixture()['_twin']);
    }

    /** @return array<string, mixed> */
    private static function loadFixture(): array
    {
        return json_decode(file_get_contents(self::FIXTURE_PATH), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{component: string, highest: int, score: string|null}>  $activities
     * @return array<string, array{raw: BigDecimal, highest: int}>
     */
    private static function sumComponents(array $activities): array
    {
        $componentTotals = array_fill_keys(GradingActivity::COMPONENTS, ['raw' => BigDecimal::zero(), 'highest' => 0]);

        foreach ($activities as $activity) {
            $total = $componentTotals[$activity['component']];
            $componentTotals[$activity['component']] = [
                'raw' => $activity['score'] === null ? $total['raw'] : $total['raw']->plus($activity['score']),
                'highest' => $total['highest'] + $activity['highest'],
            ];
        }

        return $componentTotals;
    }
}
