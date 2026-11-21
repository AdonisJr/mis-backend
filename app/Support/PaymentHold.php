<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The hold rule, computed on every read and never stored: an unpaid current assessment of an earlier school year without an active note. */
class PaymentHold
{
    public const HOLD_MESSAGE_FORMAT = 'The student has unpaid balances from earlier school years: %s.';

    public const UNPAID_YEAR_FORMAT = '%s (balance %s)';

    /** @var Collection<int, Assessment> */
    private Collection $unpaidAssessments;

    /** @var Collection<int, PromissoryNote> */
    private Collection $activeNotes;

    private bool $isAlreadyEnrolled;

    public function __construct(private readonly int $studentId, private readonly SchoolYear $targetYear)
    {
        $this->unpaidAssessments = $this->loadUnpaidAssessments();
        $this->activeNotes = $this->loadActiveNotes();
        $this->isAlreadyEnrolled = $this->hasEnrollmentInTargetYear();
    }

    public function getStudentId(): int
    {
        return $this->studentId;
    }

    public function getTargetYear(): SchoolYear
    {
        return $this->targetYear;
    }

    /** @return list<array{assessment: Assessment, balance: BigDecimal, active_note: PromissoryNote|null}> */
    public function listUnpaidYears(): array
    {
        return $this->unpaidAssessments
            ->map(fn (Assessment $assessment) => [
                'assessment' => $assessment,
                'balance' => $assessment->calculateBalance(),
                'active_note' => $this->activeNotes->get($assessment->school_year_id),
            ])
            ->values()
            ->all();
    }

    public function isOnHold(): bool
    {
        // a student already placed in the target year keeps that place, so only a first enrollment is held
        return ! $this->isAlreadyEnrolled && $this->listUncoveredYears() !== [];
    }

    public function buildHoldMessage(): string
    {
        $yearLabels = array_map(
            fn (array $unpaidYear) => sprintf(self::UNPAID_YEAR_FORMAT, $unpaidYear['assessment']->schoolYear->name, (string) $unpaidYear['balance']),
            $this->listUncoveredYears(),
        );

        return sprintf(self::HOLD_MESSAGE_FORMAT, implode(', ', $yearLabels));
    }

    /** @return list<array{assessment: Assessment, balance: BigDecimal, active_note: PromissoryNote|null}> */
    private function listUncoveredYears(): array
    {
        return array_values(array_filter($this->listUnpaidYears(), fn (array $unpaidYear) => $unpaidYear['active_note'] === null));
    }

    /** @return Collection<int, Assessment> */
    private function loadUnpaidAssessments(): Collection
    {
        // the query itself leaves out superseded, same-year and later-year assessments
        return Assessment::current()
            ->select('assessments.*')
            ->join('school_years', 'school_years.id', '=', 'assessments.school_year_id')
            ->where('assessments.student_id', $this->studentId)
            ->whereDate('school_years.start_date', '<', $this->targetYear->start_date->toDateString())
            ->orderBy('school_years.start_date')
            ->with(['schoolYear', 'payments'])
            ->get()
            ->filter(fn (Assessment $assessment) => $assessment->calculateBalance()->isPositive())
            ->values();
    }

    /** @return Collection<int, PromissoryNote> */
    private function loadActiveNotes(): Collection
    {
        if ($this->unpaidAssessments->isEmpty()) {
            return new Collection;
        }

        return PromissoryNote::active()
            ->where('promissory_notes.student_id', $this->studentId)
            ->whereIn('promissory_notes.school_year_id', $this->unpaidAssessments->pluck('school_year_id'))
            ->orderBy('promissory_notes.id')
            ->get()
            ->keyBy('school_year_id');
    }

    private function hasEnrollmentInTargetYear(): bool
    {
        return Enrollment::query()
            ->where('student_id', $this->studentId)
            ->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('sections.school_year_id', $this->targetYear->id))
            ->exists();
    }
}
