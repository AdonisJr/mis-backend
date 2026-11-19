<?php

namespace App\Support;

use App\Models\AssessmentInstallment;
use App\Models\PaymentPlan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class PaymentScheduleBuilder
{
    public const FULL_PAYMENT_LABEL = 'Full payment';

    public const DOWN_PAYMENT_LABEL = 'Down payment';

    public const INSTALLMENT_LABEL = 'Installment';

    private const FIRST_SEQUENCE = 1;

    public function __construct(private PaymentPlan $paymentPlan) {}

    /** @return Collection<int, AssessmentInstallment> */
    public function buildRows(BigDecimal $netTotal): Collection
    {
        if ($this->paymentPlan->isCash()) {
            return collect([$this->buildRow(self::FIRST_SEQUENCE, self::FULL_PAYMENT_LABEL, $netTotal)]);
        }

        $downPayment = $this->calculateDownPayment($netTotal);
        $rows = collect([$this->buildRow(self::FIRST_SEQUENCE, self::DOWN_PAYMENT_LABEL, $downPayment)]);
        $installmentAmounts = $this->splitBalance($netTotal->minus($downPayment));

        foreach ($installmentAmounts as $position => $installmentAmount) {
            $number = $position + 1;
            $dueDate = $this->paymentPlan->installments[$position]->due_date->toDateString();
            $rows->push($this->buildRow($number + self::FIRST_SEQUENCE, self::INSTALLMENT_LABEL.' '.$number, $installmentAmount, $dueDate));
        }

        return $rows;
    }

    private function buildRow(int $sequence, string $label, BigDecimal $amount, ?string $dueDate = null): AssessmentInstallment
    {
        return new AssessmentInstallment([
            'sequence' => $sequence,
            'label' => $label,
            'due_date' => $dueDate ?? today()->toDateString(),
            'amount' => AssessmentCalculator::formatMoney($amount),
        ]);
    }

    private function calculateDownPayment(BigDecimal $netTotal): BigDecimal
    {
        $value = $this->paymentPlan->down_payment_value;

        $downPayment = match ($this->paymentPlan->down_payment_type) {
            PaymentPlan::TYPE_FIXED => BigDecimal::of($value),
            PaymentPlan::TYPE_PERCENT => AssessmentCalculator::calculatePercent($netTotal, $value),
            default => BigDecimal::zero(),
        };

        return BigDecimal::min($downPayment, $netTotal);
    }

    /** @return list<BigDecimal> */
    private function splitBalance(BigDecimal $balance): array
    {
        $installmentCount = $this->paymentPlan->installments->count();
        $equalShare = $balance->dividedBy($installmentCount, AssessmentCalculator::MONEY_SCALE, RoundingMode::HalfUp);

        // a tiny balance rounded up would push the last share below zero, so it rounds down instead
        if ($equalShare->multipliedBy($installmentCount - 1)->isGreaterThan($balance)) {
            $equalShare = $balance->dividedBy($installmentCount, AssessmentCalculator::MONEY_SCALE, RoundingMode::Down);
        }

        $amounts = array_fill(0, $installmentCount - 1, $equalShare);
        $amounts[] = $balance->minus($equalShare->multipliedBy($installmentCount - 1));

        return $amounts;
    }
}
