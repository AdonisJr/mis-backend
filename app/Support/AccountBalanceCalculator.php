<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Posted-line sums per account and the sign rules the financial statements share. Sums run in PHP because SQLite decimals are REAL. */
class AccountBalanceCalculator
{
    private const MONEY_SCALE = 2;

    private const STATEMENT_TYPES = [Account::TYPE_REVENUE, Account::TYPE_EXPENSE];

    /** @return Collection<int, BigDecimal> net debit (debit minus credit) keyed by account id */
    public static function sumNetDebitsByAccount(?string $from, string $to): Collection
    {
        $netDebits = [];

        foreach (self::queryPostedLines($from, $to)->cursor() as $line) {
            $netDebits[$line->account_id] = ($netDebits[$line->account_id] ?? BigDecimal::zero())->plus($line->debit)->minus($line->credit);
        }

        return collect($netDebits);
    }

    public static function calculateBalanceAsOf(Account $account, string $asOf): BigDecimal
    {
        $accountQuery = self::queryPostedLines(null, $asOf)->where('journal_lines.account_id', $account->id);
        $netDebit = BigDecimal::zero();

        foreach ($accountQuery->cursor() as $line) {
            $netDebit = $netDebit->plus($line->debit)->minus($line->credit);
        }

        return self::toNormalSide($account, $netDebit);
    }

    public static function toNormalSide(Account $account, BigDecimal $netDebit): BigDecimal
    {
        $normalSide = $account->getNormalBalance() === Account::NORMAL_BALANCE_DEBIT ? $netDebit : $netDebit->negated();

        return $normalSide->toScale(self::MONEY_SCALE);
    }

    /**
     * Rows are the non-zero accounts in the given order. A row amount is its signed contribution to the section: contra accounts are negated.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, BigDecimal>  $netDebits
     * @return array{rows: list<array<string, mixed>>, subtotal: string}
     */
    public static function buildSection(Collection $accounts, Collection $netDebits): array
    {
        $rows = [];
        $subtotal = BigDecimal::zero();

        foreach ($accounts as $account) {
            $normalSide = self::toNormalSide($account, $netDebits->get($account->id, BigDecimal::zero()));
            $amount = $account->is_contra ? $normalSide->negated() : $normalSide;

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = self::buildStatementRow($account, $amount);
            $subtotal = $subtotal->plus($amount);
        }

        return ['rows' => $rows, 'subtotal' => self::formatAmount($subtotal)];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, BigDecimal>  $netDebits
     */
    public static function calculateNetIncome(Collection $accounts, Collection $netDebits): BigDecimal
    {
        $statementAccounts = $accounts->whereIn('type', self::STATEMENT_TYPES);
        $totalNetDebit = BigDecimal::zero();

        foreach ($statementAccounts as $account) {
            $totalNetDebit = $totalNetDebit->plus($netDebits->get($account->id, BigDecimal::zero()));
        }

        return $totalNetDebit->negated()->toScale(self::MONEY_SCALE);
    }

    public static function formatAmount(BigDecimal $amount): string
    {
        return (string) $amount->toScale(self::MONEY_SCALE);
    }

    /** @return Builder<JournalLine> */
    private static function queryPostedLines(?string $from, string $to): Builder
    {
        // whereDate: the stored date may carry a time part
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->when($from, fn (Builder $query) => $query->whereDate('journal_entries.entry_date', '>=', $from))
            ->select('journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit');
    }

    /** @return array<string, mixed> */
    private static function buildStatementRow(Account $account, BigDecimal $amount): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'is_contra' => $account->is_contra,
            'is_active' => $account->is_active,
            'amount' => self::formatAmount($amount),
        ];
    }
}
