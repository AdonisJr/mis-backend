<?php

namespace App\Support;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SectionRoster
{
    private ?int $termId = null;

    private bool $isTermResolved = false;

    public function __construct(private readonly Section $section, private readonly ?int $termNumber) {}

    public function resolveTermId(): ?int
    {
        if ($this->termNumber === null) {
            return null;
        }

        if (! $this->isTermResolved) {
            $this->isTermResolved = true;
            $this->termId = Term::where('school_year_id', $this->section->school_year_id)->where('term_number', $this->termNumber)->value('id');
        }

        return $this->termId;
    }

    /** @return array{students: Collection<int, Enrollment>, left_the_class: Collection<int, Enrollment>} */
    public function fetchGroups(): array
    {
        $termId = $this->resolveTermId();

        if ($this->termNumber !== null && $termId === null) {
            // an SHS class whose term row does not exist yet has no enrollments in its period
            return ['students' => collect(), 'left_the_class' => collect()];
        }

        $periodRows = $this->buildPeriodQuery($termId)->get();
        $listedRows = $periodRows->whereIn('status', Enrollment::LISTED_STATUSES);
        // a student with any listed row is never in the left group, even when a left row is newer
        $leftRows = $periodRows->whereIn('status', Enrollment::LEFT_STATUSES)->whereNotIn('student_id', $listedRows->pluck('student_id'));

        return [
            'students' => $this->keepLatestPerStudent($listedRows),
            'left_the_class' => $this->keepLatestPerStudent($leftRows),
        ];
    }

    /**
     * @param  Collection<int, SchoolClass>  $schoolClasses
     * @return Collection<int, int>
     */
    public static function countListedStudents(Collection $schoolClasses): Collection
    {
        $terms = Term::whereIn('school_year_id', $schoolClasses->pluck('school_year_id')->unique())->get()
            ->keyBy(fn (Term $term) => $term->school_year_id.':'.$term->term_number);
        $studentCounts = Enrollment::query()->toBase()
            ->selectRaw('section_id, term_id, COUNT(DISTINCT student_id) AS student_count')
            ->whereIn('section_id', $schoolClasses->pluck('section_id')->unique())
            ->whereIn('status', Enrollment::LISTED_STATUSES)
            ->groupBy('section_id', 'term_id')
            ->get()
            ->keyBy(fn (object $countRow) => $countRow->section_id.':'.$countRow->term_id);

        return $schoolClasses->mapWithKeys(function (SchoolClass $schoolClass) use ($terms, $studentCounts) {
            $termId = $schoolClass->term_number === null ? null : $terms->get($schoolClass->school_year_id.':'.$schoolClass->term_number)?->id;
            $hasMissingTerm = $schoolClass->term_number !== null && $termId === null;

            return [$schoolClass->id => $hasMissingTerm ? 0 : (int) ($studentCounts->get($schoolClass->section_id.':'.$termId)?->student_count ?? 0)];
        });
    }

    /** @return Builder<Enrollment> */
    private function buildPeriodQuery(?int $termId): Builder
    {
        // select only enrollment columns: the joined students table also has id and timestamps
        return Enrollment::query()
            ->select('enrollments.*')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.section_id', $this->section->id)
            ->where('enrollments.term_id', $termId)
            ->with('student')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('students.id')
            ->orderBy('enrollments.id');
    }

    /**
     * @param  Collection<int, Enrollment>  $orderedRows
     * @return Collection<int, Enrollment>
     */
    private function keepLatestPerStudent(Collection $orderedRows): Collection
    {
        $latestIds = $orderedRows->sortByDesc('id')->unique('student_id')->pluck('id');

        return $orderedRows->whereIn('id', $latestIds)->values();
    }
}
