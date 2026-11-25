<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class JournalEntryListTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    public function test_list_is_newest_first_with_row_keys_and_meta(): void
    {
        $olderId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-01']));
        $newerId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-05']));
        $sameDayId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-05']));

        $listResponse = $this->fetchList()->assertOk()->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3]);

        $this->assertSame([$sameDayId, $newerId, $olderId], array_column($listResponse->json('journal_entries'), 'id'));
        $this->assertSame(self::JOURNAL_ROW_KEYS, array_keys($listResponse->json('journal_entries.0')));
    }

    public function test_list_pages_hold_fifteen_rows(): void
    {
        JournalEntry::factory()->count(16)->create();

        $this->fetchList()->assertOk()->assertJsonCount(15, 'journal_entries')->assertJsonPath('meta.last_page', 2);
    }

    public function test_rows_show_totals_and_number_or_null(): void
    {
        $this->createPostedEntry('5040', '1010', '250.50');
        $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-01']));

        $this->fetchList()
            ->assertOk()
            ->assertJsonPath('journal_entries.0.number', 'JV-000001')
            ->assertJsonPath('journal_entries.0.debit_total', '250.50')
            ->assertJsonPath('journal_entries.0.credit_total', '250.50')
            ->assertJsonPath('journal_entries.1.number', null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideDisplayStatuses(): array
    {
        return ['draft' => ['draft'], 'posted' => ['posted'], 'reversed' => ['reversed'], 'reversal' => ['reversal']];
    }

    #[DataProvider('provideDisplayStatuses')]
    public function test_status_filter_matches_the_display_status(string $displayStatus): void
    {
        $draftId = $this->createDraft($this->buildEntryPayload());
        $postedId = $this->createPostedEntry('5040', '1010', '10.00');
        $reversedId = $this->createPostedEntry('5040', '1010', '20.00');
        $reversalId = $this->reverseEntry($reversedId, ['reason' => 'Mistake'])->json('journal_entry.id');
        $expectedIds = ['draft' => $draftId, 'posted' => $postedId, 'reversed' => $reversedId, 'reversal' => $reversalId];

        $listResponse = $this->fetchList('status='.$displayStatus)->assertOk();

        $this->assertSame([$expectedIds[$displayStatus]], array_column($listResponse->json('journal_entries'), 'id'));
    }

    public function test_invalid_status_returns_422(): void
    {
        $this->fetchList('status=void')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_date_range_includes_both_edges(): void
    {
        $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-02-28']));
        $startId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-01']));
        $endId = $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-31']));
        $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-04-01']));

        $listResponse = $this->fetchList('from=2026-03-01&to=2026-03-31')->assertOk();

        $this->assertSame([$endId, $startId], array_column($listResponse->json('journal_entries'), 'id'));
    }

    public function test_to_alone_is_accepted(): void
    {
        $this->createDraft($this->buildEntryPayload(['entry_date' => '2026-03-01']));

        $this->fetchList('to=2026-02-01')->assertOk()->assertJsonCount(0, 'journal_entries');
    }

    public function test_from_after_to_returns_422(): void
    {
        $this->fetchList('from=2026-03-02&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_account_filter_matches_entries_with_a_line_on_the_account(): void
    {
        $this->createDraft($this->buildEntryPayload());
        $utilitiesId = $this->createDraft($this->buildEntryPayload(['lines' => [$this->buildDebitLine('5030', '5.00'), $this->buildCreditLine('1020', '5.00')]]));

        $listResponse = $this->fetchList('account_id='.$this->findSeededAccount('5030')->id)->assertOk();

        $this->assertSame([$utilitiesId], array_column($listResponse->json('journal_entries'), 'id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSearchWords(): array
    {
        return [
            'number' => ['JV-000001'],
            'description' => ['rent'],
            'reference' => ['inv-0042'],
        ];
    }

    #[DataProvider('provideSearchWords')]
    public function test_search_matches_number_description_and_reference(string $searchWord): void
    {
        $matchingId = $this->createPostedEntry('5040', '1010', '10.00');
        JournalEntry::factory()->create();

        $listResponse = $this->fetchList('search='.$searchWord)->assertOk();

        $this->assertSame([$matchingId], array_column($listResponse->json('journal_entries'), 'id'));
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->seedEntryWithLines();

        DB::enableQueryLog();
        $this->fetchList()->assertOk()->assertJsonCount(1, 'journal_entries');
        $singleRowCount = $this->countLoggedQueries();

        foreach (range(1, 14) as $rowNumber) {
            $this->seedEntryWithLines();
        }

        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchList()->assertOk()->assertJsonCount(15, 'journal_entries');

        $this->assertSame($singleRowCount, $this->countLoggedQueries());
    }

    private function seedEntryWithLines(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalLine::factory()->for($entry, 'entry')->create();
        JournalLine::factory()->credit()->for($entry, 'entry')->create();
    }

    private function fetchList(string $queryString = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::JOURNAL_ENTRIES_URL.($queryString === '' ? '' : '?'.$queryString));
    }
}
