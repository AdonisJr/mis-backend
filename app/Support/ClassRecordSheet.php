<?php

namespace App\Support;

use App\Models\ClassQuarter;
use App\Models\Enrollment;
use App\Models\GradingActivity;
use App\Models\QuarterGrade;
use App\Models\SchoolClass;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class ClassRecordSheet
{
    /** @var Collection<int, GradingActivity>|null */
    private ?Collection $activities = null;

    /** @var array<int, array<int, string>>|null */
    private ?array $scoreMap = null;

    private ?ClassQuarter $classQuarter = null;

    private bool $isClassQuarterLoaded = false;

    /** @var array{students: Collection<int, Enrollment>, left_the_class: Collection<int, Enrollment>}|null */
    private ?array $rosterGroups = null;

    public function __construct(private readonly SchoolClass $schoolClass, private readonly int $quarter) {}

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function getQuarter(): int
    {
        return $this->quarter;
    }

    /** @return Collection<int, GradingActivity> */
    public function fetchActivities(): Collection
    {
        return $this->activities ??= GradingActivity::sortForRecord(
            $this->schoolClass->gradingActivities()->where('quarter', $this->quarter)->with('scores')->get(),
        );
    }

    public function findClassQuarter(): ?ClassQuarter
    {
        if (! $this->isClassQuarterLoaded) {
            $this->isClassQuarterLoaded = true;
            $this->classQuarter = $this->schoolClass->classQuarters()->where('quarter', $this->quarter)->with('submittedBy', 'events.user', 'grades')->first();
        }

        return $this->classQuarter;
    }

    /** @return array{students: Collection<int, Enrollment>, left_the_class: Collection<int, Enrollment>} */
    public function fetchRosterGroups(): array
    {
        return $this->rosterGroups ??= (new SectionRoster($this->schoolClass->section, $this->schoolClass->term_number))->fetchGroups();
    }

    /** @return array{ww: int, pt: int, qa: int} */
    public function resolveWeights(): array
    {
        $snapshot = $this->fetchSnapshots()->first();

        if (! $snapshot) {
            return $this->schoolClass->curriculumEntry->subject->getWeights();
        }

        return [
            GradingActivity::COMPONENT_WW => $snapshot->ww_weight,
            GradingActivity::COMPONENT_PT => $snapshot->pt_weight,
            GradingActivity::COMPONENT_QA => $snapshot->qa_weight,
        ];
    }

    /** @return list<string> */
    public function listComponentsWithoutActivities(): array
    {
        $usedComponents = $this->fetchActivities()->pluck('component')->all();

        return array_values(array_diff(GradingActivity::COMPONENTS, $usedComponents));
    }

    /** @return array{ww: array{percentage: string, weighted: string}, pt: array{percentage: string, weighted: string}, qa: array{percentage: string, weighted: string}, initial_grade: string, quarterly_grade: int} */
    public function computeGrades(int $studentId): array
    {
        $componentTotals = array_fill_keys(GradingActivity::COMPONENTS, ['raw' => BigDecimal::zero(), 'highest' => 0]);
        $scoreMap = $this->fetchScoreMap();

        foreach ($this->fetchActivities() as $activity) {
            $rawScore = $scoreMap[$activity->id][$studentId] ?? null;
            $total = $componentTotals[$activity->component];
            $componentTotals[$activity->component] = [
                'raw' => $rawScore === null ? $total['raw'] : $total['raw']->plus($rawScore),
                'highest' => $total['highest'] + $activity->highest_possible_score,
            ];
        }

        return GradeCalculator::calculateQuarterGrades($componentTotals, $this->resolveWeights());
    }

    /** @return list<array<string, mixed>> */
    public function buildStudentRows(): array
    {
        return $this->fetchRosterGroups()['students']->map(fn (Enrollment $enrollment) => $this->buildStudentRow($enrollment))->all();
    }

    /** @return list<array<string, mixed>> */
    public function buildLeftRows(): array
    {
        return $this->fetchRosterGroups()['left_the_class']->map(fn (Enrollment $enrollment) => $this->buildStudentRow($enrollment))->all();
    }

    /** @return array<string, mixed> */
    public function buildStudentRow(Enrollment $enrollment): array
    {
        $studentId = $enrollment->student_id;
        $snapshot = $this->fetchSnapshots()->get($studentId);
        $scoreMap = $this->fetchScoreMap();

        return [
            'enrollment_id' => $enrollment->id,
            'student_id' => $studentId,
            'last_name' => $enrollment->student->last_name,
            'first_name' => $enrollment->student->first_name,
            'middle_name' => $enrollment->student->middle_name,
            'lrn' => $enrollment->student->lrn,
            'status' => $enrollment->status,
            'scores' => $this->fetchActivities()->map(fn (GradingActivity $activity) => [
                'activity_id' => $activity->id,
                'score' => $scoreMap[$activity->id][$studentId] ?? null,
            ])->all(),
            ...($snapshot ? $this->describeSnapshot($snapshot) : $this->computeGrades($studentId)),
            'is_snapshot' => (bool) $snapshot,
        ];
    }

    /** @return Collection<int, QuarterGrade> */
    private function fetchSnapshots(): Collection
    {
        return $this->findClassQuarter()?->grades->keyBy('student_id') ?? collect();
    }

    /** @return array<int, array<int, string>> */
    private function fetchScoreMap(): array
    {
        return $this->scoreMap ??= $this->fetchActivities()
            ->mapWithKeys(fn (GradingActivity $activity) => [$activity->id => $activity->scores->pluck('score', 'student_id')->all()])
            ->all();
    }

    /** @return array<string, mixed> */
    private function describeSnapshot(QuarterGrade $snapshot): array
    {
        return [
            GradingActivity::COMPONENT_WW => ['percentage' => $snapshot->ww_percentage, 'weighted' => $snapshot->ww_weighted],
            GradingActivity::COMPONENT_PT => ['percentage' => $snapshot->pt_percentage, 'weighted' => $snapshot->pt_weighted],
            GradingActivity::COMPONENT_QA => ['percentage' => $snapshot->qa_percentage, 'weighted' => $snapshot->qa_weighted],
            'initial_grade' => $snapshot->initial_grade,
            'quarterly_grade' => $snapshot->quarterly_grade,
        ];
    }
}
