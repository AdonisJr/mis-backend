<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GeneralLedgerBuilder
{
    private const MONEY_SCALE = 2;

    public function __construct(private readonly Account $account) {}

    /** @return array<string, mixed> */
    public function build(string $from, string $to): array
    {
        $openingBalance = $this->calculateOpening($from);
        $runningBalance = $openingBalance;
        $periodDebitTotal = BigDecimal::zero();
        $periodCreditTotal = BigDecimal::zero();
        $lineRows = [];

        foreach ($this->fetchRangeLines($from, $to) as $line) {
            $runningBalance = $runningBalance->plus($this->signAmount($line));
            $periodDebitTotal = $periodDebitTotal->plus($line->debit);
            $periodCreditTotal = $periodCreditTotal->plus($line->credit);
            $lineRows[] = $this->buildLineRow($line, $runningBalance);
        }

        return [
            'account' => $this->buildAccountSummary(),
            'from' => $from,
            'to' => $to,
            'opening_balance' => $this->formatAmount($openingBalance),
            'lines' => $lineRows,
            'period_debit_total' => $this->formatAmount($periodDebitTotal),
            'period_credit_total' => $this->formatAmount($periodCreditTotal),
            'closing_balance' => $this->formatAmount($runningBalance),
        ];
    }

    /** @return Builder<JournalLine> */
    private function queryPostedLines(): Builder
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $this->account->id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED);
    }

    private function calculateOpening(string $from): BigDecimal
    {
        // whereDate: the stored date may carry a time part
        $priorLines = $this->queryPostedLines()
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->get(['journal_lines.debit', 'journal_lines.credit']);
        $openingBalance = BigDecimal::zero();

        foreach ($priorLines as $priorLine) {
            $openingBalance = $openingBalance->plus($this->signAmount($priorLine));
        }

        return $openingBalance->toScale(self::MONEY_SCALE);
    }

    /** @return Collection<int, JournalLine> */
    private function fetchRangeLines(string $from, string $to): Collection
    {
        return $this->queryPostedLines()
            ->select('journal_lines.*')
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->with('entry')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.number')
            ->orderBy('journal_lines.id')
            ->get();
    }

    private function signAmount(JournalLine $line): BigDecimal
    {
        $netDebit = BigDecimal::of($line->debit)->minus($line->credit);

        return $this->account->getNormalBalance() === Account::NORMAL_BALANCE_DEBIT ? $netDebit : $netDebit->negated();
    }

    /** @return array<string, mixed> */
    private function buildLineRow(JournalLine $line, BigDecimal $runningBalance): array
    {
        return [
            'id' => $line->id,
            'journal_entry_id' => $line->journal_entry_id,
            'number' => $line->entry->number,
            'entry_date' => $line->entry->entry_date->toDateString(),
            'description' => $line->entry->description,
            'memo' => $line->memo,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'running_balance' => $this->formatAmount($runningBalance),
        ];
    }

    /** @return array<string, mixed> */
    private function buildAccountSummary(): array
    {
        return [
            'id' => $this->account->id,
            'code' => $this->account->code,
            'name' => $this->account->name,
            'normal_balance' => $this->account->getNormalBalance(),
            'is_active' => $this->account->is_active,
        ];
    }

    private function formatAmount(BigDecimal $amount): string
    {
        return (string) $amount->toScale(self::MONEY_SCALE);
    }
}
