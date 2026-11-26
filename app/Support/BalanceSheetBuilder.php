<?php

namespace App\Support;

use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BalanceSheetBuilder
{
    /** @var Collection<int, Account> */
    private Collection $accounts;

    public function __construct(private readonly string $asOf) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $this->accounts = Account::orderBy('code')->get();
        $netDebits = AccountBalanceCalculator::sumNetDebitsByAccount(null, $this->asOf);
        $yearStart = Carbon::parse($this->asOf)->startOfYear();
        $assets = $this->buildTypeBlock(Account::TYPE_ASSET, $netDebits);
        $liabilities = $this->buildTypeBlock(Account::TYPE_LIABILITY, $netDebits);
        $equity = $this->buildEquityBlock($netDebits, $yearStart);
        $liabilitiesAndEquity = BigDecimal::of($liabilities['total'])->plus($equity['total']);

        return [
            'as_of' => $this->asOf,
            'current_year_start' => $yearStart->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_liabilities_and_equity' => AccountBalanceCalculator::formatAmount($liabilitiesAndEquity),
            'is_balanced' => BigDecimal::of($assets['total'])->isEqualTo($liabilitiesAndEquity),
        ];
    }

    /**
     * @param  Collection<int, BigDecimal>  $netDebits
     * @return array{groups: list<array<string, mixed>>, total: string}
     */
    private function buildTypeBlock(string $type, Collection $netDebits): array
    {
        $groups = [];
        $total = BigDecimal::zero();

        foreach (Account::SUBTYPES_BY_TYPE[$type] as $subtype) {
            $sectionAccounts = $this->accounts->where('subtype', $subtype);
            $section = AccountBalanceCalculator::buildSection($sectionAccounts, $netDebits);
            $groups[] = ['subtype' => $subtype, ...$section];
            $total = $total->plus($section['subtotal']);
        }

        return ['groups' => $groups, 'total' => AccountBalanceCalculator::formatAmount($total)];
    }

    /**
     * @param  Collection<int, BigDecimal>  $netDebits
     * @return array<string, mixed>
     */
    private function buildEquityBlock(Collection $netDebits, Carbon $yearStart): array
    {
        $equityBlock = $this->buildTypeBlock(Account::TYPE_EQUITY, $netDebits);
        $currentYearNetIncome = AccountBalanceCalculator::calculateNetIncome(
            $this->accounts,
            AccountBalanceCalculator::sumNetDebitsByAccount($yearStart->toDateString(), $this->asOf),
        );
        $priorYearsNetIncome = AccountBalanceCalculator::calculateNetIncome(
            $this->accounts,
            AccountBalanceCalculator::sumNetDebitsByAccount(null, $yearStart->copy()->subDay()->toDateString()),
        );
        $total = BigDecimal::of($equityBlock['total'])->plus($currentYearNetIncome)->plus($priorYearsNetIncome);

        return [
            'groups' => $equityBlock['groups'],
            'net_income_current_year' => AccountBalanceCalculator::formatAmount($currentYearNetIncome),
            'net_income_prior_years' => AccountBalanceCalculator::formatAmount($priorYearsNetIncome),
            'total' => AccountBalanceCalculator::formatAmount($total),
        ];
    }
}
