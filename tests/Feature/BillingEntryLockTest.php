<?php

namespace Tests\Feature;

use App\Http\Controllers\JournalEntryController;
use App\Models\Assessment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class BillingEntryLockTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private const ROW_KEYS = ['id', 'number', 'entry_date', 'description', 'reference', 'status', 'display_status', 'source', 'source_link', 'debit_total', 'credit_total'];

    private const DETAIL_KEY_COUNT = 21;

    private JournalEntry $assessmentEntry;

    private JournalEntry $paymentEntry;

    private JournalEntry $manualEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $assessment = $this->assessForPayments(self::TUITION_AMOUNT, ['2030-08-15', '2030-09-15']);
        $paymentId = $this->recordPayment($this->adminToken, $this->buildPaymentPayload($assessment['id']))->assertCreated()->json('payment.id');
        $this->assessmentEntry = $this->findOriginalEntry(Assessment::findOrFail($assessment['id']));
        $this->paymentEntry = $this->findOriginalEntry(Payment::findOrFail($paymentId));
        $this->manualEntry = $this->createManualPostedEntry();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSources(): array
    {
        return ['manual' => ['manual'], 'assessment' => ['assessment'], 'payment' => ['payment']];
    }

    public function test_updating_a_billing_entry_is_refused_before_the_posted_check(): void
    {
        $this->sendAs($this->adminToken, 'PUT', self::JOURNAL_ENTRIES_URL.'/'.$this->assessmentEntry->id, $this->buildUpdatePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::BILLING_LOCKED_MESSAGE)
            ->assertJsonPath('message', JournalEntryController::BILLING_LOCKED_MESSAGE);
    }

    public function test_deleting_a_billing_entry_is_refused(): void
    {
        $this->sendAs($this->adminToken, 'DELETE', self::JOURNAL_ENTRIES_URL.'/'.$this->paymentEntry->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::BILLING_LOCKED_MESSAGE);

        $this->assertDatabaseHas('journal_entries', ['id' => $this->paymentEntry->id]);
    }

    public function test_reversing_a_billing_entry_is_refused(): void
    {
        $this->reverseAs($this->assessmentEntry)
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::BILLING_LOCKED_MESSAGE);

        $this->assertNull($this->assessmentEntry->refresh()->reversed_by_id);
    }

    public function test_reversing_a_billing_reversal_is_refused(): void
    {
        $this->voidPayment($this->adminToken, Payment::firstOrFail()->id)->assertOk();
        $reversal = JournalEntry::whereNotNull('reversal_of_id')->firstOrFail();

        $this->reverseAs($reversal)
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::BILLING_LOCKED_MESSAGE);
    }

    public function test_manual_posted_entry_keeps_its_own_locked_message(): void
    {
        $this->sendAs($this->adminToken, 'PUT', self::JOURNAL_ENTRIES_URL.'/'.$this->manualEntry->id, $this->buildUpdatePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', JournalEntryController::POSTED_LOCKED_MESSAGE);
    }

    public function test_can_reverse_is_false_for_billing_entries_and_true_for_manual(): void
    {
        $this->showEntry($this->assessmentEntry)->assertJsonPath('journal_entry.can_reverse', false);
        $this->showEntry($this->paymentEntry)->assertJsonPath('journal_entry.can_reverse', false);
        $this->showEntry($this->manualEntry)->assertJsonPath('journal_entry.can_reverse', true);
    }

    #[DataProvider('provideSources')]
    public function test_source_filter_returns_only_that_source(string $source): void
    {
        $rows = $this->sendAs($this->adminToken, 'GET', self::JOURNAL_ENTRIES_URL.'?source='.$source)->assertOk()->json('journal_entries');

        $this->assertCount(1, $rows);
        $this->assertSame($source, $rows[0]['source']);
    }

    public function test_invalid_source_returns_422(): void
    {
        $this->sendAs($this->adminToken, 'GET', self::JOURNAL_ENTRIES_URL.'?source=billing')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);
    }

    public function test_rows_carry_source_and_source_link(): void
    {
        $rows = collect($this->sendAs($this->adminToken, 'GET', self::JOURNAL_ENTRIES_URL)->assertOk()->json('journal_entries'))->keyBy('id');

        $this->assertSame(self::ROW_KEYS, array_keys($rows[$this->assessmentEntry->id]));
        $this->assertSame(['type' => 'assessment', 'id' => $this->assessmentEntry->source_id], $rows[$this->assessmentEntry->id]['source_link']);
        $this->assertSame(['type' => 'payment', 'id' => $this->paymentEntry->source_id], $rows[$this->paymentEntry->id]['source_link']);
        $this->assertNull($rows[$this->manualEntry->id]['source_link']);
    }

    public function test_detail_carries_source_link_after_source(): void
    {
        $detail = $this->showEntry($this->assessmentEntry)->json('journal_entry');

        $this->assertCount(self::DETAIL_KEY_COUNT, $detail);
        $this->assertSame(['type' => 'assessment', 'id' => $this->assessmentEntry->source_id], $detail['source_link']);
        $this->assertSame('source_link', array_keys($detail)[array_search('source', array_keys($detail), true) + 1]);
        $this->showEntry($this->paymentEntry)->assertJsonPath('journal_entry.source_link.type', 'payment');
        $this->showEntry($this->manualEntry)->assertJsonPath('journal_entry.source_link', null);
    }

    private function showEntry(JournalEntry $entry): TestResponse
    {
        return $this->sendAs($this->adminToken, 'GET', self::JOURNAL_ENTRIES_URL.'/'.$entry->id)->assertOk();
    }

    private function reverseAs(JournalEntry $entry): TestResponse
    {
        return $this->sendAs($this->adminToken, 'POST', self::JOURNAL_ENTRIES_URL.'/'.$entry->id.'/reverse', ['date' => self::ASSESSED_ON, 'reason' => 'Manual attempt']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUpdatePayload(): array
    {
        return [
            'entry_date' => self::ASSESSED_ON,
            'description' => 'Edited',
            'lines' => [
                ['account_id' => $this->findAccountByCode('1010')->id, 'debit' => '10.00'],
                ['account_id' => $this->findAccountByCode('4010')->id, 'credit' => '10.00'],
            ],
        ];
    }

    private function createManualPostedEntry(): JournalEntry
    {
        $entry = JournalEntry::factory()->posted()->create(['entry_date' => self::ASSESSED_ON]);
        JournalLine::factory()->for($entry, 'entry')->create(['account_id' => $this->findAccountByCode('1010')->id, 'debit' => '10.00', 'credit' => '0.00']);
        JournalLine::factory()->for($entry, 'entry')->create(['account_id' => $this->findAccountByCode('4010')->id, 'debit' => '0.00', 'credit' => '10.00']);

        return $entry;
    }
}
