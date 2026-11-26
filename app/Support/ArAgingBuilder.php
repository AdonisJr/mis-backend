<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\PostingMapping;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ArAgingBuilder
{
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    private const BUCKET_LIMIT_DAYS = ['current' => 0, 'days_1_30' => 30, 'days_31_60' => 60, 'days_61_90' => 90];

    private const OVERDUE_BUCKET = 'over_90';

    public function __construct(private readonly string $asOf) {}

    /**
     * @param  Collection<int, Assessment>  $currentAssessments  every assessment current on the date, whatever the filters
     * @param  Collection<int, int|string>  $listedIds  the filtered assessment ids, in display order
     * @return array<string, mixed>
     */
    public function build(Collection $currentAssessments, Collection $listedIds): array
    {
        $rowsById = $currentAssessments->mapWithKeys(fn (Assessment $assessment) => [$assessment->id => $this->buildRow($assessment)])->filter();
        $listedRows = $listedIds->map(fn (int|string $assessmentId) => $rowsById->get((int) $assessmentId))->filter()->values();
        $agingTotal = PaymentAllocation::sumAmounts($rowsById->pluck('total'));

        return [
            'as_of' => $this->asOf,
            'rows' => $listedRows->all(),
            'totals' => $this->buildTotals($listedRows),
            'tie_out' => $this->buildTieOut($agingTotal),
        ];
    }

    /** @return array<string, mixed>|null null when nothing is outstanding */
    private function buildRow(Assessment $assessment): ?array
    {
        $bucketSums = array_fill_keys(self::BUCKETS, BigDecimal::zero());

        foreach ((new PaymentAllocation($assessment))->buildOutstandingRowsAsOf($this->asOf) as $outstandingRow) {
            $bucket = $this->resolveBucket($outstandingRow['due_date']);
            $bucketSums[$bucket] = $bucketSums[$bucket]->plus($outstandingRow['outstanding']);
        }

        $total = array_reduce($bucketSums, fn (BigDecimal $sum, BigDecimal $bucketSum) => $sum->plus($bucketSum), BigDecimal::zero());

        if ($total->isZero()) {
            return null;
        }

        return [
            'assessment_id' => $assessment->id,
            'student' => $assessment->student->only(['id', 'lrn', 'first_name', 'middle_name', 'last_name']),
            'school_year' => $assessment->schoolYear->only(['id', 'name']),
            'grade_level' => $assessment->gradeLevel->only(['id', 'name']),
            ...array_map(fn (BigDecimal $bucketSum) => AccountBalanceCalculator::formatAmount($bucketSum), $bucketSums),
            'total' => AccountBalanceCalculator::formatAmount($total),
        ];
    }

    private function resolveBucket(string $dueDate): string
    {
        $daysPastDue = (int) Carbon::parse($dueDate)->diffInDays(Carbon::parse($this->asOf), false);

        foreach (self::BUCKET_LIMIT_DAYS as $bucket => $limitDays) {
            if ($limitDays >= $daysPastDue) {
                return $bucket;
            }
        }

        return self::OVERDUE_BUCKET;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $listedRows
     * @return array<string, string>
     */
    private function buildTotals(Collection $listedRows): array
    {
        $totals = [];

        foreach ([...self::BUCKETS, 'total'] as $column) {
            $totals[$column] = AccountBalanceCalculator::formatAmount(PaymentAllocation::sumAmounts($listedRows->pluck($column)));
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    private function buildTieOut(BigDecimal $agingTotal): array
    {
        $arAccount = PostingMapping::loadAccountsByPurpose()->get(PostingMapping::PURPOSE_AR_STUDENTS);
        $formattedAgingTotal = AccountBalanceCalculator::formatAmount($agingTotal);

        if ($arAccount === null) {
            return ['aging_total' => $formattedAgingTotal, 'gl_balance' => null, 'difference' => null, 'is_mapped' => false];
        }

        $glBalance = AccountBalanceCalculator::calculateBalanceAsOf($arAccount, $this->asOf);

        return [
            'aging_total' => $formattedAgingTotal,
            'gl_balance' => AccountBalanceCalculator::formatAmount($glBalance),
            'difference' => AccountBalanceCalculator::formatAmount($agingTotal->minus($glBalance)),
            'is_mapped' => true,
        ];
    }
}
