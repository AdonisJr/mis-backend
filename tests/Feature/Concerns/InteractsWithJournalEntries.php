<?php

namespace Tests\Feature\Concerns;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithJournalEntries
{
    use InteractsWithAccounts;

    private const JOURNAL_ENTRIES_URL = '/api/mis/journal-entries';

    private const GENERAL_LEDGER_URL = '/api/mis/general-ledger';

    private const JOURNAL_ENTRY_KEYS = [
        'id', 'number', 'entry_date', 'description', 'reference', 'status', 'display_status', 'can_reverse', 'source', 'source_link',
        'created_by', 'posted_by', 'posted_at', 'reversal_of', 'reversed_by', 'reversal_reason', 'debit_total', 'credit_total',
        'lines', 'created_at', 'updated_at',
    ];

    private const JOURNAL_ROW_KEYS = ['id', 'number', 'entry_date', 'description', 'reference', 'status', 'display_status', 'source', 'source_link', 'debit_total', 'credit_total'];

    private const ENTRY_DATE = '2026-03-02';

    private const TEST_NOW = '2026-06-15 10:00:00';

    /**
     * @return array{account_id: int, debit: string}
     */
    private function buildDebitLine(string $code, string $amount): array
    {
        return ['account_id' => $this->findSeededAccount($code)->id, 'debit' => $amount];
    }

    /**
     * @return array{account_id: int, credit: string}
     */
    private function buildCreditLine(string $code, string $amount): array
    {
        return ['account_id' => $this->findSeededAccount($code)->id, 'credit' => $amount];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEntryPayload(array $overrides = []): array
    {
        return [
            'entry_date' => self::ENTRY_DATE,
            'description' => 'Office rent March',
            'reference' => 'INV-0042',
            'lines' => [$this->buildDebitLine('5040', '1500.00'), $this->buildCreditLine('1010', '1500.00')],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createDraft(array $payload): int
    {
        return $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)->assertCreated()->json('journal_entry.id');
    }

    private function postEntry(int $entryId): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId.'/post');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reverseEntry(int $entryId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId.'/reverse', $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPostedEntry(string $debitCode, string $creditCode, string $amount, array $overrides = []): int
    {
        $entryId = $this->createDraft($this->buildEntryPayload([
            'lines' => [$this->buildDebitLine($debitCode, $amount), $this->buildCreditLine($creditCode, $amount)],
            ...$overrides,
        ]));
        $this->postEntry($entryId)->assertOk();

        return $entryId;
    }

    /**
     * @param  list<array{string, string, string}>  $lineSpecs  account code, debit, credit
     */
    private function createFactoryDraft(array $lineSpecs): JournalEntry
    {
        $entry = JournalEntry::factory()->create(['entry_date' => self::ENTRY_DATE]);

        foreach ($lineSpecs as [$code, $debit, $credit]) {
            JournalLine::factory()->for($entry, 'entry')->create(['account_id' => $this->findSeededAccount($code)->id, 'debit' => $debit, 'credit' => $credit]);
        }

        return $entry;
    }

    private function findAdmin(): User
    {
        return User::where('email', 'acting@admin.test')->firstOrFail();
    }

    private function countLoggedQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }

    private function findInactiveAccount(): Account
    {
        return Account::factory()->inactive()->create();
    }
}
