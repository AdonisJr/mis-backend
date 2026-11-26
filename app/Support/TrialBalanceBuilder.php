<?php

namespace App\Support;

use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class TrialBalanceBuilder
{
    /** @var Collection<int, BigDecimal> */
    private Collection $netDebits;

    public function __construct(private readonly string $asOf) {}

    /** @return array<string, mixed> */
    public function build(?string $type, bool $isZeroIncluded): array
    {
        $this->netDebits = AccountBalanceCalculator::sumNetDebitsByAccount(null, $this->asOf);
        $listedAccounts = Account::orderBy('code')->get()
            ->filter(fn (Account $account) => $this->isListed($account, $isZeroIncluded) && ($type === null || $account->type === $type));
        $rows = $listedAccounts->map(fn (Account $account) => $this->buildRow($account))->values();

        return [
            'as_of' => $this->asOf,
            'type' => $type,
            'include_zero' => $isZeroIncluded,
            'rows' => $rows->all(),
            'totals' => $this->buildTotals($rows),
            // whole ledger, whatever the type filter: a filtered view would otherwise always read out of balance
            'is_balanced' => $this->isLedgerBalanced(),
        ];
    }

    private function fetchNetDebit(Account $account): BigDecimal
    {
        return $this->netDebits->get($account->id, BigDecimal::zero());
    }

    private function isListed(Account $account, bool $isZeroIncluded): bool
    {
        return ! $this->fetchNetDebit($account)->isZero() || ($isZeroIncluded && $account->is_active);
    }

    /** @return array<string, mixed> */
    private function buildRow(Account $account): array
    {
        $netDebit = $this->fetchNetDebit($account);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'is_contra' => $account->is_contra,
            'is_active' => $account->is_active,
            'debit' => AccountBalanceCalculator::formatAmount($netDebit->isPositive() ? $netDebit : BigDecimal::zero()),
            'credit' => AccountBalanceCalculator::formatAmount($netDebit->isNegative() ? $netDebit->abs() : BigDecimal::zero()),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{debit: string, credit: string}
     */
    private function buildTotals(Collection $rows): array
    {
        return [
            'debit' => AccountBalanceCalculator::formatAmount(PaymentAllocation::sumAmounts($rows->pluck('debit'))),
            'credit' => AccountBalanceCalculator::formatAmount(PaymentAllocation::sumAmounts($rows->pluck('credit'))),
        ];
    }

    private function isLedgerBalanced(): bool
    {
        $ledgerNetDebit = $this->netDebits->reduce(fn (BigDecimal $total, BigDecimal $netDebit) => $total->plus($netDebit), BigDecimal::zero());

        return $ledgerNetDebit->isZero();
    }
}
