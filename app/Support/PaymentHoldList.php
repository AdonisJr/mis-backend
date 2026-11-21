<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use Brick\Math\BigDecimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** One row per student and school year: every unpaid current assessment plus every pair that has a note. */
class PaymentHoldList
{
    public const PER_PAGE = 15;

    private const MONEY_SCALE = 2;

    /** @var Collection<string, Assessment> */
    private Collection $currentByPair;

    /** @var Collection<string, Assessment> */
    private Collection $unpaidByPair;

    /** @var Collection<string, PromissoryNote> */
    private Collection $newestNoteByPair;

    /**
     * @param  Collection<int, Assessment>  $currentAssessments
     * @param  Collection<int, PromissoryNote>  $notes  newest first
     */
    public function __construct(Collection $currentAssessments, Collection $notes)
    {
        $this->currentByPair = $currentAssessments->keyBy(fn (Assessment $assessment) => $this->buildPairKey($assessment));
        $this->unpaidByPair = $this->currentByPair->filter(fn (Assessment $assessment) => $assessment->calculateBalance()->isPositive());
        $this->newestNoteByPair = $notes
            ->unique(fn (PromissoryNote $note) => $this->buildPairKey($note))
            ->keyBy(fn (PromissoryNote $note) => $this->buildPairKey($note));
    }

    public function paginateRows(?string $noteStatus, int $page): LengthAwarePaginator
    {
        $rows = $this->buildRows()
            ->when($noteStatus !== null, fn (Collection $builtRows) => $builtRows->where('note_status', $noteStatus))
            ->values();

        return new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page);
    }

    /** @return Collection<int, array{student: Student, school_year: SchoolYear, balance: BigDecimal, note: PromissoryNote|null, note_status: string}> */
    public function buildRows(): Collection
    {
        $pairKeys = $this->unpaidByPair->keys()->merge($this->newestNoteByPair->keys())->unique();

        return $pairKeys
            ->map(fn (string $pairKey) => $this->buildRowForPair($pairKey))
            ->sortBy([
                ['student.last_name', 'asc'],
                ['student.first_name', 'asc'],
                ['student.id', 'asc'],
                fn (array $first, array $second) => $second['school_year']->start_date <=> $first['school_year']->start_date,
            ])
            ->values();
    }

    private function buildPairKey(Assessment|PromissoryNote $keyed): string
    {
        return $keyed->student_id.'-'.$keyed->school_year_id;
    }

    /** @return array{student: Student, school_year: SchoolYear, balance: BigDecimal, note: PromissoryNote|null, note_status: string} */
    private function buildRowForPair(string $pairKey): array
    {
        $sourceRecord = $this->unpaidByPair->get($pairKey) ?? $this->newestNoteByPair->get($pairKey);
        $note = $this->newestNoteByPair->get($pairKey);
        $balance = $this->resolvePairBalance($pairKey);

        return [
            'student' => $sourceRecord->student,
            'school_year' => $sourceRecord->schoolYear,
            'balance' => $balance,
            'note' => $note,
            'note_status' => $note?->assignYearBalance($balance)->deriveStatus() ?? PromissoryNote::STATUS_NONE,
        ];
    }

    private function resolvePairBalance(string $pairKey): BigDecimal
    {
        $currentAssessment = $this->currentByPair->get($pairKey);

        return $currentAssessment?->calculateBalance() ?? BigDecimal::zero()->toScale(self::MONEY_SCALE);
    }
}
