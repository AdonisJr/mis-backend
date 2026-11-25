<?php

namespace Tests\Feature;

use App\Http\Requests\StoreJournalEntryRequest;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\JournalPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class JournalEntryDraftTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    private const MANILA = 'Asia/Manila';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    public function test_create_saves_draft_with_lines_in_order(): void
    {
        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload())
            ->assertCreated()
            ->assertJsonPath('journal_entry.number', null)
            ->assertJsonPath('journal_entry.status', 'draft')
            ->assertJsonPath('journal_entry.display_status', 'draft')
            ->assertJsonPath('journal_entry.entry_date', self::ENTRY_DATE)
            ->assertJsonPath('journal_entry.created_by.id', $this->findAdmin()->id)
            ->assertJsonPath('journal_entry.lines.0.account.code', '5040')
            ->assertJsonPath('journal_entry.lines.0.debit', '1500.00')
            ->assertJsonPath('journal_entry.lines.0.credit', '0.00')
            ->assertJsonPath('journal_entry.lines.1.account.code', '1010')
            ->assertJsonPath('journal_entry.lines.1.credit', '1500.00')
            ->assertJsonPath('journal_entry.debit_total', '1500.00')
            ->assertJsonPath('journal_entry.credit_total', '1500.00');

        $this->assertSame(self::JOURNAL_ENTRY_KEYS, array_keys($createResponse->json('journal_entry')));
        $this->assertSame(2, JournalLine::count());
    }

    public function test_unbalanced_draft_is_saved(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['lines' => [$this->buildDebitLine('5040', '1500.00'), $this->buildCreditLine('1010', '1000.00')]]))
            ->assertCreated()
            ->assertJsonPath('journal_entry.debit_total', '1500.00')
            ->assertJsonPath('journal_entry.credit_total', '1000.00');
    }

    public function test_show_returns_entry_with_account_names_and_totals(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());

        $showResponse = $this->withToken($this->adminToken)->getJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId)
            ->assertOk()
            ->assertJsonPath('journal_entry.id', $entryId)
            ->assertJsonPath('journal_entry.lines.0.account.name', 'Rent')
            ->assertJsonPath('journal_entry.lines.1.account.name', 'Cash on Hand')
            ->assertJsonPath('journal_entry.can_reverse', false)
            ->assertJsonPath('journal_entry.reversal_of', null);

        $this->assertSame(self::JOURNAL_ENTRY_KEYS, array_keys($showResponse->json('journal_entry')));
    }

    public function test_update_replaces_header_and_lines(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());
        $updatePayload = $this->buildEntryPayload([
            'description' => 'Utilities April',
            'reference' => null,
            'lines' => [$this->buildDebitLine('5030', '900.00'), $this->buildCreditLine('1020', '900.00')],
        ]);

        $this->withToken($this->adminToken)->putJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId, $updatePayload)
            ->assertOk()
            ->assertJsonPath('journal_entry.description', 'Utilities April')
            ->assertJsonPath('journal_entry.reference', null)
            ->assertJsonCount(2, 'journal_entry.lines')
            ->assertJsonPath('journal_entry.lines.0.account.code', '5030')
            ->assertJsonPath('journal_entry.lines.1.account.code', '1020');

        $this->assertSame(2, JournalLine::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_delete_removes_entry_and_lines(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());

        $this->withToken($this->adminToken)->deleteJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId)->assertNoContent();

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalLine::count());
    }

    public function test_show_update_and_delete_of_missing_entry_return_404(): void
    {
        $missingUrl = self::JOURNAL_ENTRIES_URL.'/'.self::MISSING_ID;

        $this->withToken($this->adminToken)->getJson($missingUrl)->assertNotFound();
        $this->withToken($this->adminToken)->putJson($missingUrl, $this->buildEntryPayload())->assertNotFound();
        $this->withToken($this->adminToken)->deleteJson($missingUrl)->assertNotFound();
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function provideInvalidHeaders(): array
    {
        return [
            'future date' => ['entry_date', '2026-06-16', 'entry_date'],
            'missing date' => ['entry_date', null, 'entry_date'],
            'missing description' => ['description', null, 'description'],
            'description 501' => ['description', str_repeat('a', 501), 'description'],
            'reference 101' => ['reference', str_repeat('a', 101), 'reference'],
            'memo 256' => ['lines.0.memo', str_repeat('a', 256), 'lines.0.memo'],
        ];
    }

    #[DataProvider('provideInvalidHeaders')]
    public function test_invalid_header_is_rejected(string $path, mixed $value, string $errorKey): void
    {
        $payload = $this->buildEntryPayload();
        data_set($payload, $path, $value);

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_entry_date_today_in_manila_is_accepted_tomorrow_is_not(): void
    {
        // 16:30 UTC is already 00:30 of the next day in Manila
        $this->travelTo(Carbon::parse('2026-03-02 00:30:00', self::MANILA));

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['entry_date' => '2026-03-02']))->assertCreated();
        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['entry_date' => '2026-03-03']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_date');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideBadLineCounts(): array
    {
        return ['no lines' => [0], 'one line' => [1], 'too many lines' => [101]];
    }

    #[DataProvider('provideBadLineCounts')]
    public function test_bad_line_count_is_rejected(int $lineCount): void
    {
        $lines = array_map(fn (int $lineNumber) => $this->buildDebitLine('1010', '1.00'), range(1, max($lineCount, 1)));
        $lines = array_slice($lines, 0, $lineCount);

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['lines' => $lines]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideAcceptedLineCounts(): array
    {
        return ['two lines' => [2], 'hundred lines' => [100]];
    }

    #[DataProvider('provideAcceptedLineCounts')]
    public function test_boundary_line_counts_are_accepted(int $lineCount): void
    {
        $lines = array_map(
            fn (int $lineNumber) => $lineNumber % 2 === 0 ? $this->buildCreditLine('1010', '1.00') : $this->buildDebitLine('1010', '1.00'),
            range(1, $lineCount),
        );

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['lines' => $lines]))
            ->assertCreated()
            ->assertJsonCount($lineCount, 'journal_entry.lines');
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function provideInvalidAmounts(): array
    {
        return [
            'both zero' => ['0', '0'],
            'both positive' => ['10.00', '10.00'],
            'negative' => ['-5.00', null],
            'three decimals' => ['10.005', null],
            'over the column maximum' => ['1000000000.00', null],
            'not a number' => ['abc', null],
        ];
    }

    #[DataProvider('provideInvalidAmounts')]
    public function test_invalid_line_amounts_are_rejected(mixed $debit, mixed $credit): void
    {
        $payload = $this->buildEntryPayload();
        $payload['lines'][0] = ['account_id' => $this->findSeededAccount('5040')->id, 'debit' => $debit, 'credit' => $credit];

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.debit');
    }

    public function test_both_sides_zero_gets_the_one_side_message(): void
    {
        $payload = $this->buildEntryPayload();
        $payload['lines'][0] = ['account_id' => $this->findSeededAccount('5040')->id, 'debit' => '0', 'credit' => '0'];

        $errorResponse = $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)->assertUnprocessable();

        $this->assertSame([StoreJournalEntryRequest::ONE_SIDE_MESSAGE], $errorResponse->json('errors')['lines.0.debit']);
    }

    public function test_largest_amount_is_accepted(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload(['lines' => [$this->buildDebitLine('5040', '999999999.99'), $this->buildCreditLine('1010', '999999999.99')]]))
            ->assertCreated()
            ->assertJsonPath('journal_entry.debit_total', '999999999.99');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideBadAccountIds(): array
    {
        return ['missing id' => [null], 'not a number' => ['abc']];
    }

    #[DataProvider('provideBadAccountIds')]
    public function test_bad_account_id_is_rejected(mixed $accountId): void
    {
        $payload = $this->buildEntryPayload();
        $payload['lines'][0]['account_id'] = $accountId;

        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.account_id');
    }

    public function test_inactive_account_is_rejected_on_create(): void
    {
        $inactiveAccount = $this->findInactiveAccount();
        $payload = $this->buildEntryPayload();
        $payload['lines'][1]['account_id'] = $inactiveAccount->id;

        $errorResponse = $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)->assertUnprocessable();

        $this->assertSame([JournalPosting::ACCOUNT_INACTIVE_MESSAGE], $errorResponse->json('errors')['lines.1.account_id']);
    }

    public function test_update_keeps_a_line_on_an_account_deactivated_since(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());
        $this->findSeededAccount('1010')->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)->putJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId, $this->buildEntryPayload(['description' => 'Rent corrected']))
            ->assertOk()
            ->assertJsonPath('journal_entry.description', 'Rent corrected');
    }

    public function test_update_rejects_an_inactive_account_not_on_the_stored_draft(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());
        $this->findSeededAccount('1020')->forceFill(['is_active' => false])->save();
        $payload = $this->buildEntryPayload(['lines' => [$this->buildDebitLine('5040', '1500.00'), $this->buildCreditLine('1020', '1500.00')]]);

        $this->withToken($this->adminToken)->putJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.1.account_id');
    }

    public function test_protected_fields_in_payload_are_ignored(): void
    {
        $original = JournalEntry::factory()->posted()->create();
        $payload = $this->buildEntryPayload([
            'number' => 'JV-000777',
            'status' => 'posted',
            'posted_by' => $this->findAdmin()->id,
            'reversal_of_id' => $original->id,
            'created_by' => $original->created_by,
        ]);

        $createResponse = $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, $payload)
            ->assertCreated()
            ->assertJsonPath('journal_entry.number', null)
            ->assertJsonPath('journal_entry.status', 'draft')
            ->assertJsonPath('journal_entry.posted_by', null)
            ->assertJsonPath('journal_entry.reversal_of', null)
            ->assertJsonPath('journal_entry.created_by.id', $this->findAdmin()->id);

        $this->withToken($this->adminToken)->putJson(self::JOURNAL_ENTRIES_URL.'/'.$createResponse->json('journal_entry.id'), $payload)
            ->assertOk()
            ->assertJsonPath('journal_entry.number', null)
            ->assertJsonPath('journal_entry.status', 'draft');
    }
}
