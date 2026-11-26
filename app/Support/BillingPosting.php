<?php

namespace App\Support;

use App\Http\Requests\StoreJournalEntryRequest;
use App\Models\Assessment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PostingMapping;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts and reverses journal entries for billing events without ever blocking billing:
 * every refusal returns false and leaves the billing record unposted.
 * Never loads relations onto the passed models, so billing response bodies stay unchanged.
 */
class BillingPosting
{
    public const REASSESSED_REASON = 'Re-assessed';

    /** Set on every false return except "already posted" and "nothing to post"; reset at the start of each public call. */
    public ?string $lastFailureReason = null;

    public function __construct(private readonly User $poster) {}

    public function postAssessment(Assessment $assessment): bool
    {
        $this->lastFailureReason = null;

        if (BigDecimal::of($assessment->gross_total)->isZero()) {
            return false;
        }

        return $this->postRecord($assessment);
    }

    public function postPayment(Payment $payment): bool
    {
        $this->lastFailureReason = null;

        return $this->postRecord($payment);
    }

    public function reverseAssessment(Assessment $superseded): bool
    {
        $this->lastFailureReason = null;
        $reversal = JournalEntry::buildForSource($superseded);
        $reversal->forceFill(['entry_date' => $superseded->superseded_at->toDateString(), 'reversal_reason' => self::REASSESSED_REASON]);

        return $this->reverseOriginal($reversal);
    }

    public function reversePayment(Payment $payment): bool
    {
        $this->lastFailureReason = null;
        $reversal = JournalEntry::buildForSource($payment);
        $reversal->forceFill(['entry_date' => $payment->voided_at->toDateString(), 'reversal_reason' => $payment->void_reason]);

        return $this->reverseOriginal($reversal);
    }

    /** Reverses the superseded assessment's entry, then posts the replacement's; both belong to the caller's transaction. */
    public function repostAssessment(Assessment $superseded, Assessment $replacement): void
    {
        $this->reverseAssessment($superseded);
        $this->postAssessment($replacement);
    }

    private function postRecord(Assessment|Payment $record): bool
    {
        if (JournalEntry::originalsFor(JournalEntry::buildForSource($record))->exists()) {
            return false;
        }

        $lineRows = $this->resolveLineRows(BillingEntryBuilder::buildLines($record));

        if ($lineRows === null) {
            return false;
        }

        try {
            DB::transaction(fn () => $this->saveAndPost($record, $lineRows), PostingMapping::TRANSACTION_ATTEMPTS);
        } catch (ValidationException) {
            // account and balance refusals leave the billing record unposted; the savepoint already dropped the draft
            return $this->recordFailure(UnpostedEventFinder::REASON_POSTING_REFUSED);
        }

        return true;
    }

    /**
     * @param  list<array{purpose: string, debit: string, credit: string}>  $purposeLines
     * @return list<array<string, mixed>>|null
     */
    private function resolveLineRows(array $purposeLines): ?array
    {
        $accountsByPurpose = PostingMapping::loadAccountsByPurpose();
        $lineRows = [];

        foreach ($purposeLines as $purposeLine) {
            $account = $accountsByPurpose->get($purposeLine['purpose']);

            if ($account === null) {
                $this->recordFailure(UnpostedEventFinder::REASON_MAPPING_MISSING);

                return null;
            }

            if ($this->isOverLimit($purposeLine)) {
                $this->recordFailure(UnpostedEventFinder::REASON_AMOUNT_OVER_LIMIT);

                return null;
            }

            $lineRows[] = ['account_id' => $account->id, 'debit' => $purposeLine['debit'], 'credit' => $purposeLine['credit'], 'memo' => null];
        }

        return $lineRows;
    }

    /** @param  array{purpose: string, debit: string, credit: string}  $purposeLine */
    private function isOverLimit(array $purposeLine): bool
    {
        $amount = BigDecimal::of($purposeLine['debit'])->plus($purposeLine['credit']);

        return $amount->isGreaterThan(StoreJournalEntryRequest::AMOUNT_MAX);
    }

    /** @param  list<array<string, mixed>>  $lineRows */
    private function saveAndPost(Assessment|Payment $record, array $lineRows): JournalEntry
    {
        // built inside the closure so a retried transaction never re-saves a rolled-back model
        $entry = BillingEntryBuilder::buildEntry($record);
        $entry->forceFill(['created_by' => $this->poster->id])->save();
        $entry->lines()->createMany(JournalLine::buildRows($lineRows));

        return JournalPosting::post($entry, $this->poster);
    }

    private function reverseOriginal(JournalEntry $reversal): bool
    {
        $original = JournalEntry::originalsFor($reversal)->whereNull('reversed_by_id')->first();

        if ($original === null) {
            return false;
        }

        $reversal->forceFill(['reversal_of_id' => $original->id]);

        try {
            JournalPosting::reverse($reversal, $this->poster);
        } catch (ValidationException) {
            // account and balance refusals leave the billing record unposted; the savepoint already dropped the draft
            return $this->recordFailure(UnpostedEventFinder::REASON_POSTING_REFUSED);
        }

        return true;
    }

    private function recordFailure(string $reason): bool
    {
        $this->lastFailureReason = $reason;

        return false;
    }
}
