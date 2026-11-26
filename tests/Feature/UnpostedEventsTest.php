<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Support\UnpostedEventFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class UnpostedEventsTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private const OVER_LIMIT_AMOUNT = '1000000000.00';

    private const FIRST_DAY = '2029-06-01 09:00:00';

    private const SECOND_DAY = '2029-06-02 09:00:00';

    private const THIRD_DAY = '2029-06-03 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideReasons(): array
    {
        return [
            'mapping missing' => [UnpostedEventFinder::REASON_MAPPING_MISSING],
            'account inactive' => [UnpostedEventFinder::REASON_ACCOUNT_INACTIVE],
            'not posted' => [UnpostedEventFinder::REASON_NOT_POSTED],
        ];
    }

    public function test_list_is_empty_when_everything_is_posted(): void
    {
        $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 1);

        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonPath('unposted_events', [])->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('provideReasons')]
    public function test_each_event_carries_its_reason(string $reason): void
    {
        $assessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);

        match ($reason) {
            UnpostedEventFinder::REASON_MAPPING_MISSING => $this->unmapPurpose('revenue_tuition'),
            UnpostedEventFinder::REASON_ACCOUNT_INACTIVE => $this->deactivateSeeded('4010'),
            default => null,
        };

        $eventRow = $this->fetchUnposted($this->adminToken)->assertOk()->json('unposted_events.0');

        $this->assertSame(self::EVENT_KEYS, array_keys($eventRow));
        $this->assertSame([UnpostedEventFinder::TYPE_ASSESSMENT, $assessment->id, '2029-06-01', self::TUITION_AMOUNT, $reason], [$eventRow['type'], $eventRow['source_id'], $eventRow['date'], $eventRow['amount'], $eventRow['reason']]);
        $this->assertSame(UnpostedEventFinder::REASON_MESSAGES[$reason], $eventRow['reason_message']);
        $this->assertSame($assessment->student_id, $eventRow['student']['id']);
    }

    public function test_events_are_listed_oldest_first_across_types(): void
    {
        $latest = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::THIRD_DAY);
        $earliest = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::SECOND_DAY);
        $payment = $this->createBackfillPayment($earliest, '2029-06-02');
        $sameDayLater = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::SECOND_DAY);

        $eventRows = $this->fetchUnposted($this->adminToken)->assertOk()->json('unposted_events');

        $this->assertSame(
            [['assessment', $earliest->id], ['assessment', $sameDayLater->id], ['payment', $payment->id], ['assessment', $latest->id]],
            array_map(fn (array $eventRow) => [$eventRow['type'], $eventRow['source_id']], $eventRows),
        );
    }

    public function test_list_pages_by_fifteen_with_meta(): void
    {
        foreach (range(1, 17) as $dayOffset) {
            $this->createBackfillAssessment(self::TUITION_AMOUNT, Carbon::parse(self::FIRST_DAY)->addDays($dayOffset)->toDateTimeString());
        }

        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonCount(15, 'unposted_events')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 15, 'total' => 17]);
        $this->fetchUnposted($this->adminToken, 2)->assertOk()->assertJsonCount(2, 'unposted_events')->assertJsonPath('meta.current_page', 2);
        $this->sendAs($this->adminToken, 'GET', self::UNPOSTED_EVENTS_URL.'?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
    }

    public function test_never_posted_superseded_and_voided_records_are_not_listed(): void
    {
        $assessment = Assessment::factory()->superseded()->create();
        $assessment->forceFill(['gross_tuition' => self::TUITION_AMOUNT, 'gross_total' => self::TUITION_AMOUNT])->save();
        Payment::factory()->voided()->create(['assessment_id' => $assessment->id]);

        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_voided_payment_with_a_posted_original_is_listed_as_payment_void(): void
    {
        $payment = $this->postBackfillPayment('2029-06-02');
        $this->voidWithoutReversal($payment, '2029-06-05 10:00:00');

        $eventRow = $this->fetchUnposted($this->adminToken)->assertOk()->json('unposted_events.0');

        $this->assertSame([UnpostedEventFinder::TYPE_PAYMENT_VOID, $payment->id, '2029-06-05', UnpostedEventFinder::REASON_NOT_POSTED], [$eventRow['type'], $eventRow['source_id'], $eventRow['date'], $eventRow['reason']]);
        $this->assertSame('100.00', $eventRow['amount']);
    }

    public function test_superseded_assessment_with_a_posted_original_is_listed_as_assessment_reversal(): void
    {
        $assessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $this->postPending($this->adminToken)->assertJsonPath('posted_count', 1);
        $assessment->forceFill(['status' => Assessment::STATUS_SUPERSEDED, 'superseded_at' => '2029-06-09 08:00:00'])->save();

        $eventRow = $this->fetchUnposted($this->adminToken)->assertOk()->json('unposted_events.0');

        $this->assertSame([UnpostedEventFinder::TYPE_ASSESSMENT_REVERSAL, $assessment->id, '2029-06-09'], [$eventRow['type'], $eventRow['source_id'], $eventRow['date']]);
        $this->assertSame(self::TUITION_AMOUNT, $eventRow['amount']);
    }

    public function test_zero_gross_assessment_is_excluded(): void
    {
        Assessment::factory()->create();

        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_backfill_posts_with_historical_dates_and_ascending_numbers(): void
    {
        $third = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::THIRD_DAY);
        $first = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $second = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::SECOND_DAY);

        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 3)->assertJsonPath('failed_count', 0);

        $this->assertSame(['JV-000001', '2029-06-01'], $this->describeEntry($first));
        $this->assertSame(['JV-000002', '2029-06-02'], $this->describeEntry($second));
        $this->assertSame(['JV-000003', '2029-06-03'], $this->describeEntry($third));
    }

    public function test_run_reports_counts_and_failures_with_reasons(): void
    {
        $assessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $payment = $this->createBackfillPayment($assessment, '2029-06-02');
        $this->unmapPurpose('revenue_tuition');

        $response = $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 1)->assertJsonPath('failed_count', 1);

        $failureRow = $response->json('failures.0');
        $this->assertSame(self::EVENT_KEYS, array_keys($failureRow));
        $this->assertSame([UnpostedEventFinder::TYPE_ASSESSMENT, $assessment->id, UnpostedEventFinder::REASON_MAPPING_MISSING], [$failureRow['type'], $failureRow['source_id'], $failureRow['reason']]);
        $this->assertSame(UnpostedEventFinder::REASON_MESSAGES[UnpostedEventFinder::REASON_MAPPING_MISSING], $failureRow['reason_message']);
        $this->assertNotNull($this->findOriginalEntry($payment));
        $this->assertNull($this->findOriginalEntry($assessment));
    }

    public function test_second_run_posts_nothing(): void
    {
        $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $this->postPending($this->adminToken)->assertJsonPath('posted_count', 1);

        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 0)->assertJsonPath('failed_count', 0);

        $this->assertSame(1, JournalEntry::count());
    }

    public function test_never_posted_superseded_and_voided_pairs_are_skipped_on_a_run(): void
    {
        $assessment = Assessment::factory()->superseded()->create();
        $assessment->forceFill(['gross_tuition' => self::TUITION_AMOUNT, 'gross_total' => self::TUITION_AMOUNT])->save();
        Payment::factory()->voided()->create(['assessment_id' => $assessment->id]);

        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 0)->assertJsonPath('failed_count', 0);

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_void_reversal_is_dated_the_voided_at_day(): void
    {
        $payment = $this->postBackfillPayment('2029-06-02');
        $this->voidWithoutReversal($payment, '2029-06-05 10:00:00');

        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 1);

        $reversal = JournalEntry::whereNotNull('reversal_of_id')->firstOrFail();
        $this->assertSame('2029-06-05', $reversal->entry_date->toDateString());
        $this->assertSame('void', $reversal->reversal_reason);
        $this->assertNotNull($this->findOriginalEntry($payment)->reversed_by_id);
    }

    public function test_live_post_then_pending_posts_no_duplicate(): void
    {
        $this->assessWithTuition(self::TUITION_AMOUNT, $this->createCashPlan());
        $this->assertSame(1, JournalEntry::count());

        $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 0)->assertJsonPath('failed_count', 0);

        $this->assertSame(1, JournalEntry::count());
    }

    public function test_entries_are_posted_by_the_clicking_accountant(): void
    {
        $assessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $accountantToken = $this->createAccountantToken();

        $this->postPending($accountantToken)->assertOk()->assertJsonPath('posted_count', 1);

        $this->assertSame(User::where('email', 'accountant@school.test')->value('id'), $this->findOriginalEntry($assessment)->posted_by);
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $this->createBackfillPayment(Assessment::firstOrFail(), '2029-06-02');
        $this->fetchUnposted($this->adminToken)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonCount(2, 'unposted_events');
        $smallCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 7) as $dayOffset) {
            $extraAssessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, Carbon::parse(self::SECOND_DAY)->addDays($dayOffset)->toDateTimeString());
            $this->createBackfillPayment($extraAssessment, Carbon::parse(self::SECOND_DAY)->addDays($dayOffset)->toDateString());
        }

        DB::flushQueryLog();
        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonCount(15, 'unposted_events');

        $this->assertSame($smallCount, $this->countQueriesWithoutTokens());
    }

    public function test_one_failing_event_does_not_stop_the_others(): void
    {
        $this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY);
        $failingAssessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::SECOND_DAY);
        $lastAssessment = $this->createBackfillAssessment(self::TUITION_AMOUNT, self::THIRD_DAY);
        JournalEntry::creating(function (JournalEntry $entry) use ($failingAssessment) {
            if ($entry->source_id === $failingAssessment->id) {
                throw new RuntimeException('forced failure');
            }
        });

        $response = $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 2)->assertJsonPath('failed_count', 1);

        $this->assertSame([$failingAssessment->id, UnpostedEventFinder::REASON_UNEXPECTED_ERROR], [$response->json('failures.0.source_id'), $response->json('failures.0.reason')]);
        $this->assertNotNull($this->findOriginalEntry($lastAssessment));
        $this->assertNull($this->findOriginalEntry($failingAssessment));
    }

    public function test_over_limit_backfill_reports_its_reason(): void
    {
        $assessment = $this->createBackfillAssessment(self::OVER_LIMIT_AMOUNT, self::FIRST_DAY);

        $response = $this->postPending($this->adminToken)->assertOk()->assertJsonPath('posted_count', 0)->assertJsonPath('failed_count', 1);

        $this->assertSame([$assessment->id, UnpostedEventFinder::REASON_AMOUNT_OVER_LIMIT], [$response->json('failures.0.source_id'), $response->json('failures.0.reason')]);
    }

    /**
     * @return array{string, string}
     */
    private function describeEntry(Assessment $assessment): array
    {
        $entry = $this->findOriginalEntry($assessment);

        return [$entry->number, $entry->entry_date->toDateString()];
    }

    private function createBackfillPayment(Assessment $assessment, string $paymentDate): Payment
    {
        return Payment::factory()->create(['assessment_id' => $assessment->id, 'payment_date' => $paymentDate]);
    }

    /** A factory payment that Post pending has already posted. */
    private function postBackfillPayment(string $paymentDate): Payment
    {
        $payment = $this->createBackfillPayment($this->createBackfillAssessment(self::TUITION_AMOUNT, self::FIRST_DAY), $paymentDate);
        $this->postPending($this->adminToken)->assertOk();
        $this->assertNotNull($this->findOriginalEntry($payment));

        return $payment;
    }

    private function voidWithoutReversal(Payment $payment, string $voidedAt): void
    {
        $payment->forceFill(['voided_at' => $voidedAt, 'voided_by' => User::where('email', 'acting@admin.test')->value('id'), 'void_reason' => 'void'])->save();
    }
}
