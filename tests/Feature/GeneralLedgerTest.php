<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    private const LEDGER_KEYS = ['account', 'from', 'to', 'opening_balance', 'lines', 'period_debit_total', 'period_credit_total', 'closing_balance'];

    private const LINE_KEYS = ['id', 'journal_entry_id', 'number', 'entry_date', 'description', 'memo', 'debit', 'credit', 'running_balance'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    public function test_debit_normal_account_shows_opening_running_and_closing_balances(): void
    {
        $this->seedCashActivity();

        $ledgerResponse = $this->fetchLedger('1010', 'from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('ledger.account.code', '1010')
            ->assertJsonPath('ledger.account.normal_balance', 'debit')
            ->assertJsonPath('ledger.account.is_active', true)
            ->assertJsonPath('ledger.from', '2026-03-01')
            ->assertJsonPath('ledger.to', '2026-03-31')
            ->assertJsonPath('ledger.opening_balance', '1000.00')
            ->assertJsonCount(2, 'ledger.lines')
            ->assertJsonPath('ledger.lines.0.credit', '200.00')
            ->assertJsonPath('ledger.lines.0.running_balance', '800.00')
            ->assertJsonPath('ledger.lines.1.debit', '500.00')
            ->assertJsonPath('ledger.lines.1.running_balance', '1300.00')
            ->assertJsonPath('ledger.period_debit_total', '500.00')
            ->assertJsonPath('ledger.period_credit_total', '200.00')
            ->assertJsonPath('ledger.closing_balance', '1300.00');

        $this->assertSame(self::LEDGER_KEYS, array_keys($ledgerResponse->json('ledger')));
        $this->assertSame(['id', 'code', 'name', 'normal_balance', 'is_active'], array_keys($ledgerResponse->json('ledger.account')));
        $this->assertSame(self::LINE_KEYS, array_keys($ledgerResponse->json('ledger.lines.0')));
    }

    public function test_credit_normal_account_grows_with_credits(): void
    {
        $this->seedCashActivity();

        $this->fetchLedger('4010', 'from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('ledger.account.normal_balance', 'credit')
            ->assertJsonPath('ledger.opening_balance', '1000.00')
            ->assertJsonPath('ledger.lines.0.running_balance', '1500.00')
            ->assertJsonPath('ledger.period_credit_total', '500.00')
            ->assertJsonPath('ledger.period_debit_total', '0.00')
            ->assertJsonPath('ledger.closing_balance', '1500.00');
    }

    public function test_contra_account_is_credit_normal(): void
    {
        $this->createPostedEntry('5030', '1590', '300.00');

        $this->fetchLedger('1590', 'from=2026-01-01&to=2026-06-15')
            ->assertOk()
            ->assertJsonPath('ledger.account.normal_balance', 'credit')
            ->assertJsonPath('ledger.lines.0.running_balance', '300.00')
            ->assertJsonPath('ledger.closing_balance', '300.00');
    }

    public function test_range_edges_are_inclusive_and_earlier_lines_roll_into_the_opening(): void
    {
        $this->createPostedEntry('1010', '4010', '10.00', ['entry_date' => '2026-02-28']);
        $this->createPostedEntry('1010', '4010', '20.00', ['entry_date' => '2026-03-01']);
        $this->createPostedEntry('1010', '4010', '30.00', ['entry_date' => '2026-03-31']);
        $this->createPostedEntry('1010', '4010', '40.00', ['entry_date' => '2026-04-01']);

        $ledgerResponse = $this->fetchLedger('1010', 'from=2026-03-01&to=2026-03-31')->assertOk();

        $this->assertSame('10.00', $ledgerResponse->json('ledger.opening_balance'));
        $this->assertSame(['20.00', '30.00'], array_column($ledgerResponse->json('ledger.lines'), 'debit'));
        $this->assertSame('60.00', $ledgerResponse->json('ledger.closing_balance'));
    }

    public function test_drafts_are_excluded(): void
    {
        $this->createDraft($this->buildEntryPayload(['lines' => [$this->buildDebitLine('1010', '99.00'), $this->buildCreditLine('4010', '99.00')]]));

        $this->fetchLedger('1010', 'from=2026-01-01&to=2026-06-15')
            ->assertOk()
            ->assertJsonCount(0, 'ledger.lines')
            ->assertJsonPath('ledger.closing_balance', '0.00');
    }

    public function test_lines_sort_by_date_then_number_then_line_id(): void
    {
        $laterNumberId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-05', 'description' => 'Posted second']));
        $earlierNumberId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-05', 'description' => 'Posted first']));
        $this->postEntry($earlierNumberId);
        $this->postEntry($laterNumberId);
        $this->createPostedEntry('1010', '4010', '5.00', ['entry_date' => '2026-03-01']);
        $twoLineId = $this->createDraft($this->buildEntryPayload([
            'entry_date' => '2026-03-05',
            'description' => 'Two lines on cash',
            'lines' => [$this->buildDebitLine('1010', '100.00'), $this->buildCreditLine('1010', '40.00'), $this->buildCreditLine('4010', '60.00')],
        ]));
        $this->postEntry($twoLineId);

        $ledgerLines = $this->fetchLedger('1010', 'from=2026-03-01&to=2026-03-31')->assertOk()->json('ledger.lines');

        $this->assertSame(['5.00', '0.00', '0.00', '100.00', '0.00'], array_column($ledgerLines, 'debit'));
        $this->assertSame(['0.00', '1500.00', '1500.00', '0.00', '40.00'], array_column($ledgerLines, 'credit'));
        $this->assertSame(['JV-000003', 'JV-000001', 'JV-000002', 'JV-000004', 'JV-000004'], array_column($ledgerLines, 'number'));
        $this->assertSame(['Posted first', 'Posted second'], array_column(array_slice($ledgerLines, 1, 2), 'description'));
    }

    public function test_default_range_runs_from_the_start_of_the_year_to_today(): void
    {
        $this->fetchLedger('1010')
            ->assertOk()
            ->assertJsonPath('ledger.from', '2026-01-01')
            ->assertJsonPath('ledger.to', '2026-06-15');
    }

    public function test_from_after_to_returns_422(): void
    {
        $this->fetchLedger('1010', 'from=2026-03-02&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBadAccountParams(): array
    {
        return ['absent' => [''], 'unknown id' => ['account_id=999999'], 'not a number' => ['account_id=abc']];
    }

    #[DataProvider('provideBadAccountParams')]
    public function test_bad_account_id_returns_422(string $queryString): void
    {
        $this->withToken($this->adminToken)->getJson(self::GENERAL_LEDGER_URL.($queryString === '' ? '' : '?'.$queryString))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    public function test_inactive_account_still_has_a_ledger(): void
    {
        $this->createPostedEntry('1010', '4010', '10.00');
        $this->findSeededAccount('1010')->forceFill(['is_active' => false])->save();

        $this->fetchLedger('1010')
            ->assertOk()
            ->assertJsonPath('ledger.account.is_active', false)
            ->assertJsonPath('ledger.closing_balance', '10.00');
    }

    public function test_empty_ledger_is_all_zero(): void
    {
        $this->fetchLedger('1020')
            ->assertOk()
            ->assertJsonPath('ledger.opening_balance', '0.00')
            ->assertJsonPath('ledger.lines', [])
            ->assertJsonPath('ledger.period_debit_total', '0.00')
            ->assertJsonPath('ledger.period_credit_total', '0.00')
            ->assertJsonPath('ledger.closing_balance', '0.00');
        $this->assertSame(0, JournalEntry::count());
    }

    private function seedCashActivity(): void
    {
        $this->createPostedEntry('1010', '4010', '1000.00', ['entry_date' => '2026-02-01']);
        $this->createPostedEntry('5040', '1010', '200.00', ['entry_date' => '2026-03-05']);
        $this->createPostedEntry('1010', '4010', '500.00', ['entry_date' => '2026-03-10']);
    }

    private function fetchLedger(string $accountCode, string $queryString = ''): TestResponse
    {
        $accountParam = 'account_id='.$this->findSeededAccount($accountCode)->id;

        return $this->withToken($this->adminToken)->getJson(self::GENERAL_LEDGER_URL.'?'.$accountParam.($queryString === '' ? '' : '&'.$queryString));
    }
}
