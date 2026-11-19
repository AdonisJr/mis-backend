<?php

namespace App\Support;

use App\Http\Requests\StoreStudentDiscountRequest;
use App\Models\Assessment;
use App\Models\AssessmentDiscount;
use App\Models\AssessmentFeeLine;
use App\Models\DiscountType;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\PaymentPlan;
use App\Models\StudentDiscount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AssessmentCalculator
{
    public const NOT_ENROLLED_MESSAGE = StoreStudentDiscountRequest::NOT_ENROLLED_MESSAGE;

    public const NOT_BILLABLE_MESSAGE = 'Only junior and senior high school students can be assessed.';

    public const NO_FEE_SCHEDULE_MESSAGE = 'No fee schedule is set for the student\'s grade level and program in this school year.';

    public const PLAN_OTHER_YEAR_MESSAGE = 'The selected payment plan does not belong to this school year.';

    public const PLAN_INACTIVE_MESSAGE = 'The selected payment plan is inactive.';

    public const MONEY_SCALE = 2;

    private const PERCENT_DIVISOR = 100;

    private const TUITION = FeeItem::CATEGORY_TUITION;

    /** @var Collection<int, Enrollment>|null */
    private ?Collection $eligibleEnrollments = null;

    private ?FeeSchedule $feeSchedule = null;

    private bool $isFeeScheduleResolved = false;

    public function __construct(private Assessment $candidate) {}

    public static function calculatePercent(BigDecimal $base, string $percent): BigDecimal
    {
        return $base->multipliedBy($percent)->dividedBy(self::PERCENT_DIVISOR, self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    public static function formatMoney(BigDecimal $amount): string
    {
        return (string) $amount->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    /** @return array<string, string> */
    public function findEligibilityErrors(): array
    {
        $errors = [];
        $studentError = $this->findStudentError();
        $planError = $this->findPlanError();

        if ($studentError !== null) {
            $errors['student_id'] = $studentError;
        }

        if ($planError !== null) {
            $errors['payment_plan_id'] = $planError;
        }

        return $errors;
    }

    /** @throws ValidationException */
    public function buildEligibleDraft(): Assessment
    {
        $eligibilityErrors = $this->findEligibilityErrors();

        if ($eligibilityErrors !== []) {
            throw ValidationException::withMessages($eligibilityErrors);
        }

        $sourceSection = $this->resolveSourceEnrollment()->section;
        $feeLines = $this->buildFeeLines($this->resolveFeeSchedule());
        $gross = $this->sumGrossByCategory($feeLines);
        $discountRows = $this->buildDiscountRows($gross);

        $this->candidate->forceFill([
            'grade_level_id' => $sourceSection->grade_level_id,
            'program_id' => $sourceSection->program_id,
            'status' => Assessment::STATUS_CURRENT,
        ]);
        $netTotal = $this->fillTotals($gross, $this->applyDiscountCaps($discountRows, $gross));

        $this->candidate->setRelation('feeLines', $feeLines);
        $this->candidate->setRelation('discounts', $discountRows);
        $this->candidate->setRelation('installments', (new PaymentScheduleBuilder($this->candidate->paymentPlan))->buildRows($netTotal));

        return $this->candidate->load(['student', 'schoolYear', 'gradeLevel', 'program', 'creator']);
    }

    private function findStudentError(): ?string
    {
        if ($this->resolveEligibleEnrollments()->isEmpty()) {
            return self::NOT_ENROLLED_MESSAGE;
        }

        if ($this->resolveSourceEnrollment() === null) {
            return self::NOT_BILLABLE_MESSAGE;
        }

        return $this->resolveFeeSchedule() === null ? self::NO_FEE_SCHEDULE_MESSAGE : null;
    }

    private function findPlanError(): ?string
    {
        $paymentPlan = $this->candidate->paymentPlan;

        // a missing plan is already reported by the exists rule of the request
        if ($paymentPlan === null) {
            return null;
        }

        if ($paymentPlan->school_year_id !== $this->candidate->school_year_id) {
            return self::PLAN_OTHER_YEAR_MESSAGE;
        }

        return $paymentPlan->is_active ? null : self::PLAN_INACTIVE_MESSAGE;
    }

    /** @return Collection<int, Enrollment> */
    private function resolveEligibleEnrollments(): Collection
    {
        $this->eligibleEnrollments ??= Enrollment::with('section.gradeLevel')
            ->where('student_id', $this->candidate->student_id)
            ->eligibleForAssessment($this->candidate->school_year_id)
            ->get();

        return $this->eligibleEnrollments;
    }

    private function resolveSourceEnrollment(): ?Enrollment
    {
        return Assessment::pickSourceEnrollment($this->resolveEligibleEnrollments());
    }

    private function resolveFeeSchedule(): ?FeeSchedule
    {
        if ($this->isFeeScheduleResolved) {
            return $this->feeSchedule;
        }

        $this->isFeeScheduleResolved = true;
        $sourceSection = $this->resolveSourceEnrollment()?->section;

        if ($sourceSection === null) {
            return null;
        }

        $this->feeSchedule = (new FeeSchedule([
            'school_year_id' => $this->candidate->school_year_id,
            'grade_level_id' => $sourceSection->grade_level_id,
            'program_id' => $sourceSection->program_id,
        ]))->findEffective()?->load('lines.feeItem');

        return $this->feeSchedule;
    }

    /** @return Collection<int, AssessmentFeeLine> */
    private function buildFeeLines(FeeSchedule $schedule): Collection
    {
        return $schedule->lines->map(fn ($line) => new AssessmentFeeLine([
            'fee_item_name' => $line->feeItem->name,
            'category' => $line->feeItem->category,
            'amount' => $line->amount,
        ]))->values();
    }

    /**
     * @param  Collection<int, AssessmentFeeLine>  $feeLines
     * @return array<string, BigDecimal>
     */
    private function sumGrossByCategory(Collection $feeLines): array
    {
        $gross = array_fill_keys(FeeItem::CATEGORIES, BigDecimal::zero());

        foreach ($feeLines as $feeLine) {
            $gross[$feeLine->category] = $gross[$feeLine->category]->plus($feeLine->amount);
        }

        return $gross;
    }

    /**
     * @param  array<string, BigDecimal>  $gross
     * @return Collection<int, AssessmentDiscount>
     */
    private function buildDiscountRows(array $gross): Collection
    {
        $studentDiscounts = StudentDiscount::with('discountType')
            ->where('student_id', $this->candidate->student_id)
            ->where('school_year_id', $this->candidate->school_year_id)
            ->get()
            ->sort(fn (StudentDiscount $first, StudentDiscount $second) => $this->buildTrimKey($first) <=> $this->buildTrimKey($second));

        return $studentDiscounts->map(function (StudentDiscount $studentDiscount) use ($gross) {
            $discountType = $studentDiscount->discountType;
            $computedAmount = $this->calculateDiscountAmount($discountType, $gross);

            return new AssessmentDiscount([
                'discount_type_name' => $discountType->name,
                'source' => $discountType->source,
                'value_type' => $discountType->value_type,
                'value' => $discountType->value,
                'base' => $discountType->base,
                'reference_no' => $studentDiscount->reference_no,
                'computed_amount' => self::formatMoney($computedAmount),
                'applied_amount' => self::formatMoney($computedAmount),
            ]);
        })->values();
    }

    /** @return array{0: bool, 1: string, 2: int} */
    private function buildTrimKey(StudentDiscount $studentDiscount): array
    {
        // government discounts are cut first, then by name, then by id
        return [$studentDiscount->discountType->source !== DiscountType::SOURCE_GOVERNMENT, $studentDiscount->discountType->name, $studentDiscount->id];
    }

    /** @param array<string, BigDecimal> $gross */
    private function calculateDiscountAmount(DiscountType $discountType, array $gross): BigDecimal
    {
        if ($discountType->value_type === DiscountType::VALUE_TYPE_FIXED) {
            return BigDecimal::of($discountType->value);
        }

        $base = $discountType->base === DiscountType::BASE_TUITION ? $gross[self::TUITION] : BigDecimal::sum(...array_values($gross));

        return self::calculatePercent($base, $discountType->value);
    }

    /**
     * @param  Collection<int, AssessmentDiscount>  $discountRows
     * @param  array<string, BigDecimal>  $gross
     * @return array<string, BigDecimal>
     */
    private function applyDiscountCaps(Collection $discountRows, array $gross): array
    {
        $tuitionApplied = $this->applyTrim($discountRows->where('base', DiscountType::BASE_TUITION), $gross[self::TUITION]);
        $remaining = [...$gross, self::TUITION => $gross[self::TUITION]->minus($tuitionApplied)];
        $totalApplied = $this->applyTrim($discountRows->where('base', DiscountType::BASE_TOTAL), BigDecimal::sum(...array_values($remaining)));
        $categoryTotals = $this->allocateTotalDiscount($remaining, $totalApplied);

        return [...$categoryTotals, self::TUITION => $categoryTotals[self::TUITION]->plus($tuitionApplied)];
    }

    /**
     * Cuts the excess over the capacity from the rows in trim order and returns what stays applied.
     *
     * @param  Collection<int, AssessmentDiscount>  $phaseRows
     */
    private function applyTrim(Collection $phaseRows, BigDecimal $capacity): BigDecimal
    {
        $computedSum = BigDecimal::sum(BigDecimal::zero(), ...$phaseRows->map(fn (AssessmentDiscount $row) => BigDecimal::of($row->computed_amount))->all());
        $excess = BigDecimal::max($computedSum->minus($capacity), BigDecimal::zero());
        $appliedSum = $computedSum->minus($excess);

        foreach ($phaseRows as $row) {
            $computedAmount = BigDecimal::of($row->computed_amount);
            $cut = BigDecimal::min($computedAmount, $excess);
            $excess = $excess->minus($cut);
            $row->forceFill(['applied_amount' => self::formatMoney($computedAmount->minus($cut))]);
        }

        return $appliedSum;
    }

    /**
     * @param  array<string, BigDecimal>  $remaining
     * @return array<string, BigDecimal>
     */
    private function allocateTotalDiscount(array $remaining, BigDecimal $applied): array
    {
        $allocated = array_fill_keys(FeeItem::CATEGORIES, BigDecimal::zero());

        foreach (FeeItem::CATEGORIES as $category) {
            $allocated[$category] = BigDecimal::min($remaining[$category], $applied);
            $applied = $applied->minus($allocated[$category]);
        }

        return $allocated;
    }

    /**
     * @param  array<string, BigDecimal>  $gross
     * @param  array<string, BigDecimal>  $discounts
     */
    private function fillTotals(array $gross, array $discounts): BigDecimal
    {
        $paymentPlan = $this->candidate->paymentPlan;
        $adjustmentAmount = $this->calculateAdjustment($gross[self::TUITION]->minus($discounts[self::TUITION]));
        $grossTotal = BigDecimal::sum(...array_values($gross));
        $discountTotal = BigDecimal::sum(...array_values($discounts));
        $signedAdjustment = $paymentPlan->adjustment_kind === PaymentPlan::KIND_DISCOUNT ? $adjustmentAmount->negated() : $adjustmentAmount;
        $netTotal = $grossTotal->minus($discountTotal)->plus($signedAdjustment);

        $this->candidate->forceFill([
            'plan_name' => $paymentPlan->name,
            'adjustment_kind' => $paymentPlan->adjustment_kind,
            'adjustment_type' => $paymentPlan->adjustment_type,
            'adjustment_value' => $paymentPlan->adjustment_value,
            'adjustment_amount' => self::formatMoney($adjustmentAmount),
            'gross_tuition' => self::formatMoney($gross[self::TUITION]),
            'gross_miscellaneous' => self::formatMoney($gross[FeeItem::CATEGORY_MISCELLANEOUS]),
            'gross_other' => self::formatMoney($gross[FeeItem::CATEGORY_OTHER]),
            'gross_total' => self::formatMoney($grossTotal),
            'discount_tuition' => self::formatMoney($discounts[self::TUITION]),
            'discount_miscellaneous' => self::formatMoney($discounts[FeeItem::CATEGORY_MISCELLANEOUS]),
            'discount_other' => self::formatMoney($discounts[FeeItem::CATEGORY_OTHER]),
            'discount_total' => self::formatMoney($discountTotal),
            'net_total' => self::formatMoney($netTotal),
        ]);

        return $netTotal;
    }

    private function calculateAdjustment(BigDecimal $tuitionBase): BigDecimal
    {
        $paymentPlan = $this->candidate->paymentPlan;

        if ($paymentPlan->adjustment_kind === null) {
            return BigDecimal::zero();
        }

        $amount = $paymentPlan->adjustment_type === PaymentPlan::TYPE_PERCENT
            ? self::calculatePercent($tuitionBase, $paymentPlan->adjustment_value)
            : BigDecimal::of($paymentPlan->adjustment_value);

        return $paymentPlan->adjustment_kind === PaymentPlan::KIND_DISCOUNT ? BigDecimal::min($amount, $tuitionBase) : $amount;
    }
}
