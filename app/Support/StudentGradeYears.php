<?php

namespace App\Support;

use App\Models\FeeSchedule;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentGradeYears
{
    public function __construct(private readonly Student $student) {}

    /**
     * Non-trimester years where the student has a billable-stage enrollment, newest first. These are exactly the years where the report card builds.
     *
     * @return Collection<int, SchoolYear>
     */
    public function listYears(): Collection
    {
        return SchoolYear::query()
            ->where('term_type', '!=', SchoolYear::TERM_TYPE_TRIMESTER)
            ->whereHas('sections', fn (Builder $sectionQuery) => $sectionQuery
                ->whereHas('gradeLevel', fn (Builder $levelQuery) => $levelQuery->whereIn('stage', FeeSchedule::BILLABLE_STAGES))
                ->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery
                    ->where('student_id', $this->student->id)
                    ->whereIn('status', StudentDiscount::ELIGIBLE_ENROLLMENT_STATUSES)))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /** @param  Collection<int, SchoolYear>  $years */
    public function resolveDefaultYear(Collection $years): ?SchoolYear
    {
        $activeYear = $years->firstWhere('is_active', true);

        if ($activeYear) {
            return $activeYear;
        }

        $gradedYearIds = $this->fetchGradedYearIds($years);

        return $years->first(fn (SchoolYear $year) => $gradedYearIds->contains($year->id));
    }

    /**
     * @param  Collection<int, SchoolYear>  $years
     * @return Collection<int, int>
     */
    private function fetchGradedYearIds(Collection $years): Collection
    {
        return DB::table('quarter_grades')
            ->join('class_quarters', 'class_quarters.id', '=', 'quarter_grades.class_quarter_id')
            ->join('classes', 'classes.id', '=', 'class_quarters.class_id')
            ->where('quarter_grades.student_id', $this->student->id)
            ->whereIn('classes.school_year_id', $years->pluck('id'))
            ->distinct()
            ->pluck('classes.school_year_id');
    }
}
