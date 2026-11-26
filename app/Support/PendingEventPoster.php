<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PostingMapping;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Post pending: posts every unposted billing event, one transaction per event so a failure never undoes the others. */
class PendingEventPoster
{
    public const OUTCOME_POSTED = 'posted';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_SKIPPED = 'skipped';

    /** Why the last postEvent call ended as failed; null otherwise. */
    public ?string $lastFailureReason = null;

    private readonly UnpostedEventFinder $eventFinder;

    private readonly BillingPosting $billingPosting;

    public function __construct(User $poster)
    {
        $this->eventFinder = new UnpostedEventFinder;
        $this->billingPosting = new BillingPosting($poster);
    }

    /** @return array{posted_count: int, failed_count: int, failures: Collection<int, array{type: string, date: string, record: Assessment|Payment, reason: string}>} */
    public function postAll(): array
    {
        $postedCount = 0;
        $failedKeys = collect();
        $failureReasons = [];

        foreach ($this->eventFinder->listEventKeys() as $eventKey) {
            $outcome = $this->postEvent($eventKey);
            $postedCount += $outcome === self::OUTCOME_POSTED ? 1 : 0;

            if ($outcome === self::OUTCOME_FAILED) {
                $failedKeys->push($eventKey);
                $failureReasons[] = $this->lastFailureReason;
            }
        }

        return [
            'posted_count' => $postedCount,
            'failed_count' => $failedKeys->count(),
            'failures' => $this->attachFailureReasons($failedKeys, $failureReasons),
        ];
    }

    /** @param  array{type: string, id: int, date: string}  $eventKey */
    public function postEvent(array $eventKey): string
    {
        $this->lastFailureReason = null;

        try {
            return DB::transaction(fn () => $this->postWithinLock($eventKey), PostingMapping::TRANSACTION_ATTEMPTS);
        } catch (Throwable) {
            // not logged: the failures list in the response is the record, and the loop must keep going
            $this->lastFailureReason = UnpostedEventFinder::REASON_UNEXPECTED_ERROR;

            return self::OUTCOME_FAILED;
        }
    }

    /** @param  array{type: string, id: int, date: string}  $eventKey */
    private function postWithinLock(array $eventKey): string
    {
        $isPaymentEvent = in_array($eventKey['type'], [UnpostedEventFinder::TYPE_PAYMENT, UnpostedEventFinder::TYPE_PAYMENT_VOID], true);
        // read unlocked, then lock student -> payment, the order PaymentController::recordWithinLock takes
        $studentId = $isPaymentEvent ? Payment::whereKey($eventKey['id'])->value('student_id') : Assessment::whereKey($eventKey['id'])->value('student_id');
        Student::whereKey($studentId)->lockForUpdate()->first();

        if ($isPaymentEvent) {
            Payment::whereKey($eventKey['id'])->lockForUpdate()->first();
        }

        if (! $this->eventFinder->isStillPending($eventKey)) {
            return self::OUTCOME_SKIPPED;
        }

        if ($this->dispatchPost($eventKey['type'], $this->eventFinder->loadRecord($eventKey))) {
            return self::OUTCOME_POSTED;
        }

        $this->lastFailureReason = $this->billingPosting->lastFailureReason ?? UnpostedEventFinder::REASON_NOT_POSTED;

        return self::OUTCOME_FAILED;
    }

    private function dispatchPost(string $type, Assessment|Payment $record): bool
    {
        return match ($type) {
            UnpostedEventFinder::TYPE_ASSESSMENT => $this->billingPosting->postAssessment($record),
            UnpostedEventFinder::TYPE_PAYMENT => $this->billingPosting->postPayment($record),
            UnpostedEventFinder::TYPE_PAYMENT_VOID => $this->billingPosting->reversePayment($record),
            UnpostedEventFinder::TYPE_ASSESSMENT_REVERSAL => $this->billingPosting->reverseAssessment($record),
        };
    }

    /**
     * @param  Collection<int, array{type: string, id: int, date: string}>  $failedKeys
     * @param  list<string|null>  $failureReasons
     * @return Collection<int, array{type: string, date: string, record: Assessment|Payment, reason: string}>
     */
    private function attachFailureReasons(Collection $failedKeys, array $failureReasons): Collection
    {
        return $this->eventFinder->hydrateEvents($failedKeys)
            ->map(fn (array $event, int $index) => [...$event, 'reason' => $failureReasons[$index]])
            ->values();
    }
}
