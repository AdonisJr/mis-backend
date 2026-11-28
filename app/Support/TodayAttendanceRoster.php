<?php

namespace App\Support;

use App\Models\Enrollment;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class TodayAttendanceRoster
{
    /** @param array<string, mixed> $filters the validated Today input, `date` always set */
    public function __construct(private readonly array $filters) {}

    /**
     * One roster row per student: the latest row of the shared "current enrollment" rule.
     *
     * @param  Builder<Enrollment>  $enrollmentQuery
     * @return Builder<Enrollment>
     */
    public static function applyActivePeriod(Builder $enrollmentQuery): Builder
    {
        // a student can match several rows when no term is active (e.g. SHS term 1 and term 2), and the roster lists them once
        $latestCurrentIds = Enrollment::current()->selectRaw('MAX(enrollments.id)')->groupBy('enrollments.student_id');

        return $enrollmentQuery->whereIn('enrollments.id', $latestCurrentIds);
    }

    /** @return Builder<Enrollment> */
    public function buildRowQuery(): Builder
    {
        $noScanStatus = $this->isWeekend() ? null : StudentAttendance::STATUS_ABSENT;

        $rowQuery = $this->buildBaseQuery()
            ->select('enrollments.*', 'student_attendances.id as attendance_id', 'student_attendances.time_in', 'student_attendances.time_out')
            ->selectRaw('CASE WHEN student_attendances.id IS NULL THEN ? ELSE student_attendances.status END AS attendance_status', [$noScanStatus])
            ->with(['student', 'section.gradeLevel'])
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('students.id');
        $this->applyStatusFilter($rowQuery);

        return $rowQuery;
    }

    /** @return array{enrolled: int, present: int, late: int, absent: int, timed_out: int} */
    public function countSummary(): array
    {
        $totals = $this->buildBaseQuery()->toBase()->selectRaw(
            'COUNT(*) AS enrolled, '
            .'SUM(CASE WHEN student_attendances.status = ? THEN 1 ELSE 0 END) AS present, '
            .'SUM(CASE WHEN student_attendances.status = ? THEN 1 ELSE 0 END) AS late, '
            .'SUM(CASE WHEN student_attendances.id IS NULL THEN 1 ELSE 0 END) AS no_scan, '
            .'SUM(CASE WHEN student_attendances.time_out IS NOT NULL THEN 1 ELSE 0 END) AS timed_out',
            [StudentAttendance::STATUS_PRESENT, StudentAttendance::STATUS_LATE]
        )->first();

        return [
            'enrolled' => (int) $totals->enrolled,
            'present' => (int) ($totals->present ?? 0),
            'late' => (int) ($totals->late ?? 0),
            'absent' => $this->isWeekend() ? 0 : (int) ($totals->no_scan ?? 0),
            'timed_out' => (int) ($totals->timed_out ?? 0),
        ];
    }

    /** @return Builder<Enrollment> */
    private function buildBaseQuery(): Builder
    {
        $baseQuery = Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->leftJoin('student_attendances', fn (JoinClause $join) => $join
                ->on('student_attendances.student_id', '=', 'enrollments.student_id')
                ->whereDate('student_attendances.date', $this->filters['date']))
            ->when($this->filters['grade_level_id'] ?? null, fn (Builder $query, $gradeLevelId) => $query->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('grade_level_id', $gradeLevelId)))
            ->when($this->filters['section_id'] ?? null, fn (Builder $query, $sectionId) => $query->where('enrollments.section_id', $sectionId));

        return self::applyActivePeriod($baseQuery);
    }

    /** @param Builder<Enrollment> $rowQuery */
    private function applyStatusFilter(Builder $rowQuery): void
    {
        $status = $this->filters['status'] ?? null;

        if (! $status) {
            return;
        }

        if ($status !== StudentAttendance::STATUS_ABSENT) {
            $rowQuery->where('student_attendances.status', $status);

            return;
        }

        $rowQuery->whereNull('student_attendances.id')
            ->when($this->isWeekend(), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    private function isWeekend(): bool
    {
        return CarbonImmutable::parse($this->filters['date'])->isWeekend();
    }
}
