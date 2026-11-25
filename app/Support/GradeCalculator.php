<?php

namespace App\Support;

use App\Models\GradingActivity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class GradeCalculator
{
    public const PASSING_GRADE = 75;

    public const REMARK_PASSED = 'Passed';

    public const REMARK_FAILED = 'Failed';

    public const DESCRIPTOR_LOWEST = 'Did Not Meet Expectations';

    // lowest rounded average that earns each descriptor, highest first
    public const DESCRIPTORS = [
        90 => 'Outstanding',
        85 => 'Very Satisfactory',
        80 => 'Satisfactory',
        75 => 'Fairly Satisfactory',
    ];

    public const TRANSMUTATION_PIVOT = 60;

    public const UPPER_STEP = '1.6';

    public const LOWER_STEP = 4;

    public const SCORE_SCALE = 2;

    private const PERCENT_DIVISOR = 100;

    private const UPPER_BASE = 75;

    /**
     * @param  array<string, array{raw: BigDecimal, highest: int}>  $componentTotals
     * @param  array<string, int>  $weights
     * @return array{ww: array{percentage: string, weighted: string}, pt: array{percentage: string, weighted: string}, qa: array{percentage: string, weighted: string}, initial_grade: string, quarterly_grade: int}
     */
    public static function calculateQuarterGrades(array $componentTotals, array $weights): array
    {
        $quarterGrades = [];
        $initialGrade = BigDecimal::zero();

        foreach (GradingActivity::COMPONENTS as $component) {
            $percentage = self::calculatePercentage($componentTotals[$component]);
            $weighted = $percentage->multipliedBy($weights[$component])->dividedBy(self::PERCENT_DIVISOR, self::SCORE_SCALE, RoundingMode::HalfUp);
            $initialGrade = $initialGrade->plus($weighted);
            $quarterGrades[$component] = ['percentage' => (string) $percentage, 'weighted' => (string) $weighted];
        }

        $quarterGrades['initial_grade'] = (string) $initialGrade->toScale(self::SCORE_SCALE);
        $quarterGrades['quarterly_grade'] = self::transmute($initialGrade);

        return $quarterGrades;
    }

    public static function transmute(BigDecimal $initialGrade): int
    {
        $pivot = BigDecimal::of(self::TRANSMUTATION_PIVOT);

        if ($initialGrade->isLessThan($pivot)) {
            return self::TRANSMUTATION_PIVOT + $initialGrade->dividedBy(self::LOWER_STEP, 0, RoundingMode::Down)->toInt();
        }

        return self::UPPER_BASE + $initialGrade->minus($pivot)->dividedBy(self::UPPER_STEP, 0, RoundingMode::Down)->toInt();
    }

    /** @param list<int|null> $quarterlyGrades */
    public static function calculateFinalGrade(array $quarterlyGrades): ?int
    {
        if (! $quarterlyGrades || in_array(null, $quarterlyGrades, true)) {
            return null;
        }

        return self::calculateMean($quarterlyGrades, 0)->toInt();
    }

    /** @param list<int|null> $finalGrades */
    public static function calculateGeneralAverage(array $finalGrades): ?BigDecimal
    {
        if (! $finalGrades || in_array(null, $finalGrades, true)) {
            return null;
        }

        return self::calculateMean($finalGrades, self::SCORE_SCALE);
    }

    public static function roundAverage(BigDecimal $average): int
    {
        return $average->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function describeAverage(BigDecimal $average): string
    {
        $roundedAverage = self::roundAverage($average);

        foreach (self::DESCRIPTORS as $threshold => $descriptor) {
            if ($roundedAverage >= $threshold) {
                return $descriptor;
            }
        }

        return self::DESCRIPTOR_LOWEST;
    }

    public static function deriveRemarks(int $finalGrade): string
    {
        return $finalGrade >= self::PASSING_GRADE ? self::REMARK_PASSED : self::REMARK_FAILED;
    }

    /** @param array{raw: BigDecimal, highest: int} $componentTotal */
    private static function calculatePercentage(array $componentTotal): BigDecimal
    {
        if ($componentTotal['highest'] === 0) {
            return BigDecimal::zero()->toScale(self::SCORE_SCALE);
        }

        return $componentTotal['raw']->multipliedBy(self::PERCENT_DIVISOR)->dividedBy($componentTotal['highest'], self::SCORE_SCALE, RoundingMode::HalfUp);
    }

    /** @param list<int> $grades */
    private static function calculateMean(array $grades, int $scale): BigDecimal
    {
        return BigDecimal::of(array_sum($grades))->dividedBy(count($grades), $scale, RoundingMode::HalfUp);
    }
}
