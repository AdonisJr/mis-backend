<?php

namespace Tests\Feature;

use App\Http\Controllers\JournalEntryController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\JournalPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    public function test_posting_a_balanced_draft_assigns_the_next_voucher_number(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());

        $this->postEntry($entryId)
            ->assertOk()
            ->assertJsonPath('journal_entry.number', 'JV-000001')
            ->assertJsonPath('journal_entry.status', 'posted')
            ->assertJsonPath('journal_entry.display_status', 'posted')
            ->assertJsonPath('journal_entry.can_reverse', true)
            ->assertJsonPath('journal_entry.posted_by.id', $this->findAdmin()->id)
            ->assertJsonPath('journal_entry.created_by.id', $this->findAdmin()->id);

        $postedEntry = JournalEntry::findOrFail($entryId);
        $this->assertSame('JV-000001', $postedEntry->number);
        $this->assertNotNull($postedEntry->posted_at);
    }

    public function test_unbalanced_entry_cannot_be_posted(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload(['lines' => [$this->buildDebitLine('5040', '1500.00'), $this->buildCreditLine('1010', '1000.00')]]));

        $postResponse = $this->postEntry($entryId)->assertUnprocessable();

        $this->assertSame(
            ['Debits and credits must be equal and greater than zero. Debits: 1500.00, credits: 1000.00.'],
            $postResponse->json('errors.lines'),
        );
        $this->assertSame(JournalEntry::STATUS_DRAFT, JournalEntry::findOrFail($entryId)->status);
    }

    public function test_zero_total_entry_cannot_be_posted(): void
    {
        $entry = $this->createFactoryDraft([['1010', '0.00', '0.00'], ['4010', '0.00', '0.00']]);

        $this->postEntry($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.lines.0', 'Debits and credits must be equal and greater than zero. Debits: 0.00, credits: 0.00.');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideBadLineCounts(): array
    {
        return ['one line' => [1], 'hundred and one lines' => [101]];
    }

    #[DataProvider('provideBadLineCounts')]
    public function test_post_refuses_a_bad_line_count(int $lineCount): void
    {
        $entry = JournalEntry::factory()->create();
        JournalLine::factory()->count($lineCount)->for($entry, 'entry')->create(['account_id' => $this->findSeededAccount('1010')->id]);

        $this->postEntry($entry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.lines.0', JournalPosting::LINE_COUNT_MESSAGE);
    }

    public function test_inactive_account_blocks_posting(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());
        $this->findSeededAccount('1010')->forceFill(['is_active' => false])->save();

        $postResponse = $this->postEntry($entryId)->assertUnprocessable();

        $this->assertSame([JournalPosting::ACCOUNT_INACTIVE_MESSAGE], $postResponse->json('errors')['lines.1.account_id']);
    }

    public function test_numbers_are_sequential_across_entries(): void
    {
        foreach ([1, 2, 3] as $voucherNumber) {
            $entryId = $this->createDraft($this->buildEntryPayload());

            $this->postEntry($entryId)->assertJsonPath('journal_entry.number', sprintf('JV-%06d', $voucherNumber));
        }

        $this->assertSame(3, DB::table('receipt_counters')->where('series', 'journal_voucher')->value('last_number'));
    }

    public function test_refused_post_consumes_no_number(): void
    {
        $unbalancedId = $this->createDraft($this->buildEntryPayload(['lines' => [$this->buildDebitLine('5040', '10.00'), $this->buildCreditLine('1010', '5.00')]]));
        $this->postEntry($unbalancedId)->assertUnprocessable();
        $this->assertSame(0, DB::table('receipt_counters')->where('series', 'journal_voucher')->count());

        $this->postEntry($this->createDraft($this->buildEntryPayload()))->assertJsonPath('journal_entry.number', 'JV-000001');
    }

    public function test_failed_post_consumes_no_number(): void
    {
        $entryId = $this->createDraft($this->buildEntryPayload());
        JournalEntry::updating(fn () => throw new RuntimeException('Simulated failure after the number was allocated'));

        $this->postEntry($entryId)->assertStatus(500);

        JournalEntry::flushEventListeners();
        $this->assertSame(0, DB::table('receipt_counters')->where('series', 'journal_voucher')->count());
        $this->assertNull(JournalEntry::findOrFail($entryId)->number);
        $this->postEntry($entryId)->assertJsonPath('journal_entry.number', 'JV-000001');
    }

    public function test_posting_a_posted_entry_returns_422(): void
    {
        $entryId = $this->createPostedEntry('5040', '1010', '100.00');

        $this->postEntry($entryId)
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalPosting::ALREADY_POSTED_MESSAGE);
        $this->assertSame(1, DB::table('receipt_counters')->where('series', 'journal_voucher')->value('last_number'));
    }

    public function test_posted_entry_cannot_be_updated(): void
    {
        $entryId = $this->createPostedEntry('5040', '1010', '100.00');

        $this->withToken($this->adminToken)->putJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId, $this->buildEntryPayload(['description' => 'Changed']))
            ->assertUnprocessable()
            ->assertJsonPath('message', JournalEntryController::POSTED_LOCKED_MESSAGE)
            ->assertJsonPath('errors.entry.0', 'Posted entries cannot be changed. Reverse it instead.');

        $this->assertSame('Office rent March', JournalEntry::findOrFail($entryId)->description);
        $this->assertSame('100.00', JournalLine::where('journal_entry_id', $entryId)->orderBy('id')->firstOrFail()->debit);
    }

    public function test_posted_entry_cannot_be_deleted(): void
    {
        $entryId = $this->createPostedEntry('5040', '1010', '100.00');

        $this->withToken($this->adminToken)->deleteJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId)
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::POSTED_LOCKED_MESSAGE);

        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(2, JournalLine::count());
    }

    public function test_posting_a_missing_entry_returns_404(): void
    {
        $this->postEntry(self::MISSING_ID)->assertNotFound();
    }

    public function test_voucher_series_is_independent_of_the_receipt_series(): void
    {
        DB::table('receipt_counters')->insert(['series' => 'acknowledgment_receipt', 'last_number' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $this->postEntry($this->createDraft($this->buildEntryPayload()))->assertJsonPath('journal_entry.number', 'JV-000001');

        $this->assertSame(5, DB::table('receipt_counters')->where('series', 'acknowledgment_receipt')->value('last_number'));
    }

    public function test_service_keeps_the_source_of_a_billing_entry(): void
    {
        $entry = $this->createFactoryDraft([['1010', '100.00', '0.00'], ['4010', '0.00', '100.00']]);
        $entry->forceFill(['source' => 'billing', 'source_type' => 'payment', 'source_id' => 7])->save();

        $postedEntry = JournalPosting::post($entry, $this->findAdmin());

        $this->assertSame('JV-000001', $postedEntry->number);
        $this->assertSame('billing', $postedEntry->source);
        $this->assertSame(JournalEntry::STATUS_POSTED, JournalEntry::findOrFail($entry->id)->status);
    }
}
