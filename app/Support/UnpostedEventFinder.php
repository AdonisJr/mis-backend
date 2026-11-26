<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PostingMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Derives the billing events that have no journal entry yet, oldest first, with the reason each is still pending. */
class UnpostedEventFinder
{
    public const TYPE_ASSESSMENT = 'assessment';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_PAYMENT_VOID = 'payment_void';

    public const TYPE_ASSESSMENT_REVERSAL = 'assessment_reversal';

    public const TYPE_ORDER = [self::TYPE_ASSESSMENT, self::TYPE_PAYMENT, self::TYPE_PAYMENT_VOID, self::TYPE_ASSESSMENT_REVERSAL];

    public const REASON_MAPPING_MISSING = 'mapping_missing';

    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';

    public const REASON_NOT_POSTED = 'not_posted';

    public const REASON_AMOUNT_OVER_LIMIT = 'amount_over_limit';

    public const REASON_POSTING_REFUSED = 'posting_refused';

    public const REASON_UNEXPECTED_ERROR = 'unexpected_error';

    public const REASON_MESSAGES = [
        self::REASON_MAPPING_MISSING => 'A posting purpose it needs has no account mapped.',
        self::REASON_ACCOUNT_INACTIVE => 'An account it posts to is inactive.',
        self::REASON_NOT_POSTED => 'It has not been posted yet.',
        self::REASON_AMOUNT_OVER_LIMIT => 'An amount is above the journal line limit.',
        self::REASON_POSTING_REFUSED => 'The journal refused the entry: an account is inactive or the entry does not balance.',
        self::REASON_UNEXPECTED_ERROR => 'An unexpected error stopped this event; the others were still processed.',
    ];

    public const PER_PAGE = 15;

    public const ASSESSMENT_EVENT_RELATIONS = ['student', 'schoolYear', 'gradeLevel', 'discounts'];

    public const PAYMENT_EVENT_RELATIONS = ['student'];

    private const ASSESSMENT_TYPES = [self::TYPE_ASSESSMENT, self::TYPE_ASSESSMENT_REVERSAL];

    private const REVERSAL_TYPES = [self::TYPE_PAYMENT_VOID, self::TYPE_ASSESSMENT_REVERSAL];

    private const DATE_COLUMN_BY_TYPE = [
        self::TYPE_ASSESSMENT => 'created_at',
        self::TYPE_PAYMENT => 'payment_date',
        self::TYPE_PAYMENT_VOID => 'voided_at',
        self::TYPE_ASSESSMENT_REVERSAL => 'superseded_at',
    ];

    /** @var Collection<string, Account|null> */
    private readonly Collection $accountsByPurpose;

    public function __construct()
    {
        $this->accountsByPurpose = PostingMapping::loadAccountsByPurpose();
    }

    /** @return Collection<int, array{type: string, id: int, date: string}> */
    public function listEventKeys(): Collection
    {
        return collect(self::TYPE_ORDER)
            ->flatMap(fn (string $type) => $this->listEventKeysOfType($type))
            ->sort(fn (array $first, array $second) => $this->buildSortKey($first) <=> $this->buildSortKey($second))
            ->values();
    }

    public function paginate(int $page): LengthAwarePaginatorContract
    {
        $eventKeys = $this->listEventKeys();
        $pageKeys = $eventKeys->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return new LengthAwarePaginator($this->hydrateEvents($pageKeys), $eventKeys->count(), self::PER_PAGE, $page);
    }

    /**
     * One query per record kind, whatever the number of events.
     *
     * @param  Collection<int, array{type: string, id: int, date: string}>  $eventKeys
     * @return Collection<int, array{type: string, date: string, record: Assessment|Payment, reason: string}>
     */
    public function hydrateEvents(Collection $eventKeys): Collection
    {
        $assessmentIds = $eventKeys->filter(fn (array $eventKey) => $this->isAssessmentType($eventKey['type']))->pluck('id');
        $paymentIds = $eventKeys->reject(fn (array $eventKey) => $this->isAssessmentType($eventKey['type']))->pluck('id');
        $assessments = Assessment::whereIn('id', $assessmentIds)->with(self::ASSESSMENT_EVENT_RELATIONS)->get()->keyBy('id');
        $payments = Payment::whereIn('id', $paymentIds)->with(self::PAYMENT_EVENT_RELATIONS)->get()->keyBy('id');

        return $eventKeys->map(function (array $eventKey) use ($assessments, $payments) {
            $record = $this->isAssessmentType($eventKey['type']) ? $assessments->get($eventKey['id']) : $payments->get($eventKey['id']);

            return $this->buildEvent($eventKey, $record);
        })->values();
    }

    /** Single reload for the locked re-check inside the poster's transaction. */
    public function loadRecord(array $eventKey): Assessment|Payment
    {
        if ($this->isAssessmentType($eventKey['type'])) {
            return Assessment::with(self::ASSESSMENT_EVENT_RELATIONS)->findOrFail($eventKey['id']);
        }

        return Payment::with(self::PAYMENT_EVENT_RELATIONS)->findOrFail($eventKey['id']);
    }

    public function isStillPending(array $eventKey): bool
    {
        return $this->buildPendingQuery($eventKey['type'])->whereKey($eventKey['id'])->exists();
    }

    /** @return Collection<int, array{type: string, id: int, date: string}> */
    private function listEventKeysOfType(string $type): Collection
    {
        $dateColumn = self::DATE_COLUMN_BY_TYPE[$type];

        return $this->buildPendingQuery($type)->get(['id', $dateColumn])->map(fn (Assessment|Payment $record) => [
            'type' => $type,
            'id' => $record->id,
            'date' => $record->{$dateColumn}->toDateString(),
        ]);
    }

    /** @return Builder<Assessment>|Builder<Payment> */
    private function buildPendingQuery(string $type): Builder
    {
        return match ($type) {
            self::TYPE_ASSESSMENT => Assessment::query()->current()->where('assessments.gross_total', '>', 0)
                ->whereNotExists($this->buildOriginalQuery('assessments', 'assessment')),
            self::TYPE_PAYMENT => Payment::query()->valid()
                ->whereNotExists($this->buildOriginalQuery('payments', 'payment')),
            self::TYPE_PAYMENT_VOID => Payment::query()->voided()
                ->whereExists($this->buildOriginalQuery('payments', 'payment')->whereNull('journal_entries.reversed_by_id')),
            self::TYPE_ASSESSMENT_REVERSAL => Assessment::query()->where('assessments.status', Assessment::STATUS_SUPERSEDED)
                ->whereExists($this->buildOriginalQuery('assessments', 'assessment')->whereNull('journal_entries.reversed_by_id')),
        };
    }

    private function buildOriginalQuery(string $table, string $sourceType): QueryBuilder
    {
        return DB::table('journal_entries')->selectRaw('1')
            ->whereColumn('journal_entries.source_id', $table.'.id')
            ->where('journal_entries.source_type', $sourceType)
            ->whereNull('journal_entries.reversal_of_id');
    }

    /**
     * @param  array{type: string, id: int, date: string}  $eventKey
     * @return array{string, int, int}
     */
    private function buildSortKey(array $eventKey): array
    {
        return [$eventKey['date'], array_search($eventKey['type'], self::TYPE_ORDER, true), $eventKey['id']];
    }

    private function isAssessmentType(string $type): bool
    {
        return in_array($type, self::ASSESSMENT_TYPES, true);
    }

    /**
     * @param  array{type: string, id: int, date: string}  $eventKey
     * @return array{type: string, date: string, record: Assessment|Payment, reason: string}
     */
    private function buildEvent(array $eventKey, Assessment|Payment $record): array
    {
        return ['type' => $eventKey['type'], 'date' => $eventKey['date'], 'record' => $record, 'reason' => $this->resolveReason($eventKey['type'], $record)];
    }

    private function resolveReason(string $type, Assessment|Payment $record): string
    {
        if (in_array($type, self::REVERSAL_TYPES, true)) {
            return self::REASON_NOT_POSTED;
        }

        $neededAccounts = collect(BillingEntryBuilder::buildLines($record))->map(fn (array $line) => $this->accountsByPurpose->get($line['purpose']));

        return match (true) {
            $neededAccounts->contains(fn (?Account $account) => $account === null) => self::REASON_MAPPING_MISSING,
            $neededAccounts->contains(fn (Account $account) => ! $account->is_active) => self::REASON_ACCOUNT_INACTIVE,
            default => self::REASON_NOT_POSTED,
        };
    }
}
