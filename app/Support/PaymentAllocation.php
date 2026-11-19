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
        $remaining = $this->assessment->calculateTotalPaid();
        $scheduleRows = [];

        foreach ($this->assessment->installments as $installment) {
            $installmentAmount = BigDecimal::of($installment->amount);
            $paid = $remaining->isLessThan($installmentAmount) ? $remaining : $installmentAmount;
            $remaining = $remaining->minus($paid);
            $scheduleRows[] = $this->buildScheduleRow($installment, $paid);
        }

        return $scheduleRows;
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
