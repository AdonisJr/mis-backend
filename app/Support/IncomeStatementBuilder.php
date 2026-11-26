<?php

namespace App\Support;

use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class IncomeStatementBuilder
{
    /** @var Collection<int, Account> */
    private Collection $accounts;

    /** @var Collection<int, BigDecimal> */
    private Collection $netDebits;

    public function __construct(private readonly string $from, private readonly string $to) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $this->accounts = Account::orderBy('code')->get();
        $this->netDebits = AccountBalanceCalculator::sumNetDebitsByAccount($this->from, $this->to);
        $revenue = $this->buildSectionFor(Account::SUBTYPE_OPERATING_REVENUE, false);
        $contraRevenue = $this->buildSectionFor(Account::SUBTYPE_OPERATING_REVENUE, true);
        $otherIncome = $this->buildSectionFor(Account::SUBTYPE_OTHER_INCOME, null);
        $operatingExpenses = $this->buildSectionFor(Account::SUBTYPE_OPERATING_EXPENSE, null);
        $otherExpenses = $this->buildSectionFor(Account::SUBTYPE_OTHER_EXPENSE, null);
        $netRevenue = BigDecimal::of($revenue['subtotal'])->plus($contraRevenue['subtotal']);
        $netIncome = $netRevenue->plus($otherIncome['subtotal'])->minus($operatingExpenses['subtotal'])->minus($otherExpenses['subtotal']);

        return [
            'from' => $this->from,
            'to' => $this->to,
            'revenue' => $revenue,
            'contra_revenue' => $contraRevenue,
            'net_revenue' => AccountBalanceCalculator::formatAmount($netRevenue),
            'other_income' => $otherIncome,
            'operating_expenses' => $operatingExpenses,
            'other_expenses' => $otherExpenses,
            'net_income' => AccountBalanceCalculator::formatAmount($netIncome),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, subtotal: string} */
    private function buildSectionFor(string $subtype, ?bool $isContra): array
    {
        return AccountBalanceCalculator::buildSection($this->filterAccounts($subtype, $isContra), $this->netDebits);
    }

    /** @return Collection<int, Account> */
    private function filterAccounts(string $subtype, ?bool $isContra): Collection
    {
        return $this->accounts->filter(fn (Account $account) => $account->subtype === $subtype && ($isContra === null || $account->is_contra === $isContra));
    }
}
