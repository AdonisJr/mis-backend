<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AssessmentDiscount;
use App\Models\DiscountType;
use App\Models\FeeItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PostingMapping;
use App\Models\Student;
use Brick\Math\BigDecimal;

class BillingEntryBuilder
{
    public const ASSESSMENT_DESCRIPTION_FORMAT = 'Assessment — %s, SY %s, %s';

    public const PAYMENT_DESCRIPTION_FORMAT = 'Payment %s — %s';

    private const MONEY_SCALE = 2;

    private const GROSS_COLUMN_BY_CATEGORY = [
        FeeItem::CATEGORY_TUITION => 'gross_tuition',
        FeeItem::CATEGORY_MISCELLANEOUS => 'gross_miscellaneous',
        FeeItem::CATEGORY_OTHER => 'gross_other',
    ];

    /** An unsaved entry with its header fields; the lines come from buildLines(). */
    public static function buildEntry(Assessment|Payment $record): JournalEntry
    {
        $entry = JournalEntry::buildForSource($record);
        $isPayment = $record instanceof Payment;
        $entry->forceFill([
            'entry_date' => $isPayment ? $record->payment_date->toDateString() : $record->created_at->toDateString(),
            'description' => $isPayment ? self::buildPaymentDescription($record) : self::buildAssessmentDescription($record),
            'reference' => $isPayment ? $record->ar_number : null,
        ]);

        return $entry;
    }

    /** @return list<array{purpose: string, debit: string, credit: string}> */
    public static function buildLines(Assessment|Payment $record): array
    {
        $purposeLines = $record instanceof Payment ? self::buildPaymentLines($record) : self::buildAssessmentLines($record);

        return array_values(array_filter($purposeLines, fn (array $line) => ! BigDecimal::of($line['debit'])->plus($line['credit'])->isZero()));
    }

    /** @return list<array{purpose: string, debit: string, credit: string}> */
    private static function buildAssessmentLines(Assessment $assessment): array
    {
        $isDiscountAdjustment = $assessment->adjustment_kind === PaymentPlan::KIND_DISCOUNT;
        $isSurchargeAdjustment = $assessment->adjustment_kind === PaymentPlan::KIND_SURCHARGE;
        $debitLines = [
            self::buildDebitLine(PostingMapping::PURPOSE_AR_STUDENTS, BigDecimal::of($assessment->net_total)),
            self::buildDebitLine(PostingMapping::PURPOSE_SCHOOL_DISCOUNTS, self::sumDiscounts($assessment, DiscountType::SOURCE_SCHOOL)),
            self::buildDebitLine(PostingMapping::PURPOSE_RECEIVABLE_GOVERNMENT, self::sumDiscounts($assessment, DiscountType::SOURCE_GOVERNMENT)),
            self::buildDebitLine(PostingMapping::PURPOSE_CASH_PAYMENT_DISCOUNT, $isDiscountAdjustment ? BigDecimal::of($assessment->adjustment_amount) : BigDecimal::zero()),
        ];
        $creditLines = array_map(
            fn (string $category) => self::buildCreditLine(PostingMapping::REVENUE_PURPOSE_BY_CATEGORY[$category], BigDecimal::of($assessment->{self::GROSS_COLUMN_BY_CATEGORY[$category]})),
            FeeItem::CATEGORIES,
        );
        $creditLines[] = self::buildCreditLine(PostingMapping::PURPOSE_INSTALLMENT_SURCHARGE, $isSurchargeAdjustment ? BigDecimal::of($assessment->adjustment_amount) : BigDecimal::zero());

        return [...$debitLines, ...$creditLines];
    }

    /** @return list<array{purpose: string, debit: string, credit: string}> */
    private static function buildPaymentLines(Payment $payment): array
    {
        $amount = BigDecimal::of($payment->amount);

        return [
            self::buildDebitLine(PostingMapping::CASH_PURPOSE_BY_METHOD[$payment->method], $amount),
            self::buildCreditLine(PostingMapping::PURPOSE_AR_STUDENTS, $amount),
        ];
    }

    /** @return array{purpose: string, debit: string, credit: string} */
    private static function buildDebitLine(string $purpose, BigDecimal $amount): array
    {
        return ['purpose' => $purpose, 'debit' => (string) $amount->toScale(self::MONEY_SCALE), 'credit' => '0.00'];
    }

    /** @return array{purpose: string, debit: string, credit: string} */
    private static function buildCreditLine(string $purpose, BigDecimal $amount): array
    {
        return ['purpose' => $purpose, 'debit' => '0.00', 'credit' => (string) $amount->toScale(self::MONEY_SCALE)];
    }

    private static function sumDiscounts(Assessment $assessment, string $source): BigDecimal
    {
        $appliedAmounts = $assessment->discounts
            ->filter(fn (AssessmentDiscount $discount) => $discount->source === $source)
            ->pluck('applied_amount');

        return PaymentAllocation::sumAmounts($appliedAmounts);
    }

    private static function buildAssessmentDescription(Assessment $assessment): string
    {
        return sprintf(self::ASSESSMENT_DESCRIPTION_FORMAT, self::formatStudentName($assessment->student), $assessment->schoolYear->name, $assessment->gradeLevel->name);
    }

    private static function buildPaymentDescription(Payment $payment): string
    {
        return sprintf(self::PAYMENT_DESCRIPTION_FORMAT, $payment->ar_number, self::formatStudentName($payment->student));
    }

    private static function formatStudentName(Student $student): string
    {
        return $student->first_name.' '.$student->last_name;
    }
}
