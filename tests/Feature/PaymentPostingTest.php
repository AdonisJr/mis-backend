<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PostingMapping;
use App\Support\JournalNumberAllocator;
use App\Support\ReceiptNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class PaymentPostingTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private const BACKDATED = '2030-06-20';

    /** @var array<string, mixed> */
    private array $assessment;

    private string $cashierToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->assessment = $this->assessForPayments(self::TUITION_AMOUNT, ['2030-08-15', '2030-09-15']);
        $this->cashierToken = $this->createCashierToken();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMethods(): array
    {
        return [
            'cash' => ['cash', '1010'],
            'check' => ['check', '1010'],
            'e-wallet' => ['e_wallet', '1020'],
            'bank transfer' => ['bank_transfer', '1020'],
        ];
    }

    #[DataProvider('provideMethods')]
    public function test_payment_posts_to_the_cash_account_of_its_method(string $method, string $cashCode): void
    {
        $payment = $this->recordAndFind(['method' => $method, 'reference_no' => 'REF-1', 'payment_date' => self::BACKDATED]);
        $entry = $this->findOriginalEntry($payment);

        $this->assertSame([$cashCode => [self::PAYMENT_AMOUNT, '0.00'], '1100' => ['0.00', self::PAYMENT_AMOUNT]], $this->mapLinesByCode($entry));
        $this->assertSame([JournalEntry::SOURCE_PAYMENT, $payment->id, 'AR-000001', self::BACKDATED], [$entry->source_type, $entry->source_id, $entry->reference, $entry->entry_date->toDateString()]);
        $this->assertSame(sprintf('Payment AR-000001 — %s %s', $payment->student->first_name, $payment->student->last_name), $entry->description);
        $this->assertSame($payment->recorded_by, $entry->posted_by);
    }

    public function test_void_reverses_the_entry_with_the_void_reason_and_keeps_the_original(): void
    {
        $payment = $this->recordAndFind();
        $this->voidPayment($this->cashierToken, $payment->id, 'Entered twice')->assertOk();

        $original = $this->findOriginalEntry($payment);
        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

        $this->assertSame($reversal->id, $original->reversed_by_id);
        $this->assertSame(['Entered twice', JournalEntry::SOURCE_PAYMENT, $payment->id], [$reversal->reversal_reason, $reversal->source_type, $reversal->source_id]);
        $this->assertSame(['1010' => ['0.00', self::PAYMENT_AMOUNT], '1100' => [self::PAYMENT_AMOUNT, '0.00']], $this->mapLinesByCode($reversal));
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
    }

    public function test_void_reversal_is_dated_the_manila_void_day(): void
    {
        $payment = $this->recordAndFind();
        // 16:30 UTC is already the next calendar day in Manila
        Carbon::setTestNow(Carbon::parse('2030-07-01 16:30:00', 'UTC')->setTimezone(self::MANILA));

        $this->voidPayment($this->adminToken, $payment->id)->assertOk();

        $reversal = JournalEntry::whereNotNull('reversal_of_id')->firstOrFail();
        $this->assertSame('2030-07-02', $reversal->entry_date->toDateString());
    }

    public function test_void_of_a_never_posted_payment_creates_no_reversal(): void
    {
        $this->unmapPurpose('cash_cash');
        $payment = $this->recordAndFind();
        $this->assertNull($this->findOriginalEntry($payment));

        $this->voidPayment($this->cashierToken, $payment->id)->assertOk();

        $this->assertSame(0, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_unmapped_cash_purpose_leaves_the_payment_unposted(): void
    {
        $this->unmapPurpose('cash_cash');

        $payment = $this->recordAndFind();

        $this->assertNull($this->findOriginalEntry($payment));
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(0, PostingMapping::where('purpose', 'cash_cash')->count());
    }

    public function test_inactive_mapped_account_leaves_payment_unposted(): void
    {
        $this->deactivateSeeded('1010');

        $payment = $this->recordAndFind();

        $this->assertNull($this->findOriginalEntry($payment));
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(1, JournalLine::distinct()->count('journal_entry_id'));
        $this->assertSame(1, DB::table('receipt_counters')->where('series', JournalNumberAllocator::JV_SERIES)->value('last_number'));
    }

    public function test_unexpected_posting_error_fails_and_saves_nothing(): void
    {
        JournalLine::creating(fn () => throw new RuntimeException('forced failure'));

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertStatus(500);

        $this->assertSame(0, Payment::count());
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(0, DB::table('receipt_counters')->where('series', ReceiptNumberAllocator::AR_SERIES)->count());
    }

    /**
     * @param  array<string, mixed>  $paymentFields
     */
    private function recordAndFind(array $paymentFields = []): Payment
    {
        $paymentId = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => self::PAYMENT_AMOUNT, ...$paymentFields]))
            ->assertCreated()
            ->json('payment.id');

        return Payment::with('student')->findOrFail($paymentId);
    }
}
