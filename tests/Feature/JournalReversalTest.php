<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Support\JournalPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class JournalReversalTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    private const REASON = 'Wrong account';

    private int $originalId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
        $this->originalId = $this->createPostedEntry('5040', '1010', '1500.00');
    }

    public function test_reversal_swaps_the_lines_and_posts_a_new_voucher(): void
    {
        $this->reverseEntry($this->originalId, ['reason' => self::REASON, 'date' => '2026-06-01'])
            ->assertCreated()
            ->assertJsonPath('journal_entry.number', 'JV-000002')
            ->assertJsonPath('journal_entry.status', 'posted')
            ->assertJsonPath('journal_entry.entry_date', '2026-06-01')
            ->assertJsonPath('journal_entry.description', 'Reversal of JV-000001: Wrong account')
            ->assertJsonPath('journal_entry.reversal_reason', self::REASON)
            ->assertJsonPath('journal_entry.reference', null)
            ->assertJsonPath('journal_entry.posted_by.id', $this->findAdmin()->id)
            ->assertJsonPath('journal_entry.lines.0.account.code', '5040')
            ->assertJsonPath('journal_entry.lines.0.debit', '0.00')
            ->assertJsonPath('journal_entry.lines.0.credit', '1500.00')
            ->assertJsonPath('journal_entry.lines.1.account.code', '1010')
            ->assertJsonPath('journal_entry.lines.1.debit', '1500.00')
            ->assertJsonPath('journal_entry.lines.1.credit', '0.00');
    }

    public function test_original_and_reversal_link_to_each_other(): void
    {
        $reversalId = $this->reverseEntry($this->originalId, ['reason' => self::REASON])->assertCreated()->json('journal_entry.id');

        $this->withToken($this->adminToken)->getJson(self::JOURNAL_ENTRIES_URL.'/'.$this->originalId)
            ->assertOk()
            ->assertJsonPath('journal_entry.display_status', 'reversed')
            ->assertJsonPath('journal_entry.can_reverse', false)
            ->assertJsonPath('journal_entry.reversed_by', ['id' => $reversalId, 'number' => 'JV-000002']);
        $this->withToken($this->adminToken)->getJson(self::JOURNAL_ENTRIES_URL.'/'.$reversalId)
            ->assertOk()
            ->assertJsonPath('journal_entry.display_status', 'reversal')
            ->assertJsonPath('journal_entry.can_reverse', false)
            ->assertJsonPath('journal_entry.reversal_of', ['id' => $this->originalId, 'number' => 'JV-000001']);
    }

    public function test_entry_can_be_reversed_only_once(): void
    {
        $this->reverseEntry($this->originalId, ['reason' => self::REASON])->assertCreated();

        $this->reverseEntry($this->originalId, ['reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalPosting::ALREADY_REVERSED_MESSAGE);
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_a_reversal_entry_cannot_be_reversed(): void
    {
        $reversalId = $this->reverseEntry($this->originalId, ['reason' => self::REASON])->assertCreated()->json('journal_entry.id');

        $this->reverseEntry($reversalId, ['reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalPosting::REVERSAL_NOT_REVERSIBLE_MESSAGE);
    }

    public function test_a_draft_cannot_be_reversed(): void
    {
        $draftId = $this->createDraft($this->buildEntryPayload());

        $this->reverseEntry($draftId, ['reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalPosting::NOT_POSTED_MESSAGE);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBadDates(): array
    {
        return ['day before the original' => ['2026-03-01'], 'tomorrow' => ['2026-06-16']];
    }

    #[DataProvider('provideBadDates')]
    public function test_reversal_date_outside_the_allowed_range_is_rejected(string $date): void
    {
        $this->reverseEntry($this->originalId, ['reason' => self::REASON, 'date' => $date])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        $this->assertSame(1, JournalEntry::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEdgeDates(): array
    {
        return ['the original date' => ['2026-03-02'], 'today' => ['2026-06-15']];
    }

    #[DataProvider('provideEdgeDates')]
    public function test_reversal_date_edges_are_inclusive(string $date): void
    {
        $this->reverseEntry($this->originalId, ['reason' => self::REASON, 'date' => $date])
            ->assertCreated()
            ->assertJsonPath('journal_entry.entry_date', $date);
    }

    public function test_reversal_date_defaults_to_today(): void
    {
        $this->reverseEntry($this->originalId, ['reason' => self::REASON])
            ->assertCreated()
            ->assertJsonPath('journal_entry.entry_date', '2026-06-15');
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function provideBadReasons(): array
    {
        return ['missing' => [null], 'too long' => [501]];
    }

    #[DataProvider('provideBadReasons')]
    public function test_bad_reason_is_rejected(?int $reasonLength): void
    {
        $payload = $reasonLength === null ? [] : ['reason' => str_repeat('a', $reasonLength)];

        $this->reverseEntry($this->originalId, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_reason_of_500_chars_fits_the_description(): void
    {
        $reason = str_repeat('a', 500);

        $reversalId = $this->reverseEntry($this->originalId, ['reason' => $reason])
            ->assertCreated()
            ->json('journal_entry.id');

        $this->assertSame('Reversal of JV-000001: '.$reason, JournalEntry::findOrFail($reversalId)->description);
    }

    public function test_reversal_succeeds_when_an_account_was_deactivated(): void
    {
        $this->findSeededAccount('1010')->forceFill(['is_active' => false])->save();

        $this->reverseEntry($this->originalId, ['reason' => self::REASON])->assertCreated();
    }

    public function test_reversing_a_missing_entry_returns_404(): void
    {
        $this->reverseEntry(self::MISSING_ID, ['reason' => self::REASON])->assertNotFound();
    }

    public function test_failed_reversal_rolls_back_everything(): void
    {
        JournalEntry::updating(function (JournalEntry $entry) {
            if ($entry->isDirty('reversed_by_id')) {
                throw new RuntimeException('Simulated failure after the reversal was posted');
            }
        });

        $this->reverseEntry($this->originalId, ['reason' => self::REASON])->assertStatus(500);

        JournalEntry::flushEventListeners();
        $this->assertSame(1, JournalEntry::count());
        $this->assertNull(JournalEntry::findOrFail($this->originalId)->reversed_by_id);
        $this->assertSame(1, DB::table('receipt_counters')->where('series', 'journal_voucher')->value('last_number'));
    }
}
