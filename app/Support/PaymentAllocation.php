<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\AssessmentInstallment;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/** Fills the installment schedule oldest due first from the non-voided payments; derived on every read, never stored. */
class PaymentAllocation
{
    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_UNPAID = 'unpaid';

    private const MONEY_SCALE = 2;

    public function __construct(private readonly Assessment $assessment) {}

    /** @param  Collection<int, Payment>  $payments */
    public static function sumValidAmounts(Collection $payments): BigDecimal
    {
        $validAmounts = $payments->reject(fn (Payment $payment) => $payment->isVoided())->pluck('amount');

        return self::sumAmounts($validAmounts);
    }

    /** @param  iterable<int, string>  $amounts */
    public static function sumAmounts(iterable $amounts): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total->toScale(self::MONEY_SCALE);
    }

    /** @return list<array{sequence: int, label: string, due_date: string, amount: string, paid: string, status: string, is_overdue: bool}> */
    public function buildScheduleRows(): array
    {
        $filledPairs = $this->fillOldestDueFirst($this->assessment->calculateTotalPaid());

        return array_map(fn (array $filledPair) => $this->buildScheduleRow($filledPair['installment'], $filledPair['paid']), $filledPairs);
    }

    /**
     * Outstanding amount per installment on a past date. Payments beyond the schedule total come back as one extra negative row dated the as-of day.
     *
     * @return list<array{due_date: string, outstanding: string}>
     */
    public function buildOutstandingRowsAsOf(string $asOf): array
    {
        $paidTotal = self::sumPaidAsOf($this->assessment->payments, $asOf);
        $outstandingRows = array_map(fn (array $filledPair) => [
            'due_date' => $filledPair['installment']->due_date->toDateString(),
            'outstanding' => (string) BigDecimal::of($filledPair['installment']->amount)->minus($filledPair['paid'])->toScale(self::MONEY_SCALE),
        ], $this->fillOldestDueFirst($paidTotal));
        $overpayment = $paidTotal->minus(self::sumAmounts($this->assessment->installments->pluck('amount')));

        if ($overpayment->isPositive()) {
            $outstandingRows[] = ['due_date' => $asOf, 'outstanding' => (string) $overpayment->negated()->toScale(self::MONEY_SCALE)];
        }

        return $outstandingRows;
    }

    public function calculateNextAmountDue(): BigDecimal
    {
        foreach ($this->buildScheduleRows() as $scheduleRow) {
            if ($scheduleRow['status'] !== self::STATUS_PAID) {
                return BigDecimal::of($scheduleRow['amount'])->minus($scheduleRow['paid'])->toScale(self::MONEY_SCALE);
            }
        }

        return BigDecimal::zero()->toScale(self::MONEY_SCALE);
    }

    /** @return list<array{installment: AssessmentInstallment, paid: BigDecimal}> */
    private function fillOldestDueFirst(BigDecimal $paidTotal): array
    {
        $remaining = $paidTotal;
        $filledPairs = [];

        foreach ($this->assessment->installments as $installment) {
            $installmentAmount = BigDecimal::of($installment->amount);
            $paid = $remaining->isLessThan($installmentAmount) ? $remaining : $installmentAmount;
            $remaining = $remaining->minus($paid);
            $filledPairs[] = ['installment' => $installment, 'paid' => $paid];
        }

        return $filledPairs;
    }

    /** @param  Collection<int, Payment>  $payments */
    private static function sumPaidAsOf(Collection $payments, string $asOf): BigDecimal
    {
        $countedAmounts = $payments->filter(fn (Payment $payment) => self::isCountedAsOf($payment, $asOf))->pluck('amount');

        return self::sumAmounts($countedAmounts);
    }

    private static function isCountedAsOf(Payment $payment, string $asOf): bool
    {
        if ($payment->payment_date->toDateString() > $asOf) {
            return false;
        }

        return $payment->voided_at === null || $payment->voided_at->toDateString() > $asOf;
    }

    /** @return array{sequence: int, label: string, due_date: string, amount: string, paid: string, status: string, is_overdue: bool} */
    private function buildScheduleRow(AssessmentInstallment $installment, BigDecimal $paid): array
    {
        $installmentAmount = BigDecimal::of($installment->amount);
        $status = $this->resolveStatus($installmentAmount, $paid);

        return [
            'sequence' => $installment->sequence,
            'label' => $installment->label,
            'due_date' => $installment->due_date->toDateString(),
            'amount' => (string) $installmentAmount->toScale(self::MONEY_SCALE),
            'paid' => (string) $paid->toScale(self::MONEY_SCALE),
            'status' => $status,
            // due today is not overdue: the payer can still pay on the due date
            'is_overdue' => $status !== self::STATUS_PAID && $installment->due_date->lt(today()),
        ];
    }

    private function resolveStatus(BigDecimal $installmentAmount, BigDecimal $paid): string
    {
        if ($paid->isEqualTo($installmentAmount)) {
            return self::STATUS_PAID;
        }

        return $paid->isPositive() ? self::STATUS_PARTIAL : self::STATUS_UNPAID;
    }
}
