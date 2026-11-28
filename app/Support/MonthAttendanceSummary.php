<?php

namespace App\Support;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class MonthAttendanceSummary
{
    private const MONTH_INPUT_FORMAT = '!Y-m';

    private readonly CarbonImmutable $monthStart;

    public function __construct(private readonly Student $student, string $month)
    {
        $this->monthStart = CarbonImmutable::createFromFormat(self::MONTH_INPUT_FORMAT, $month);
    }

    /** @return Collection<int, StudentAttendance> */
    public function listDays(): Collection
    {
        return $this->student->attendances()
            ->whereDate('date', '>=', $this->monthStart->toDateString())
            ->whereDate('date', '<=', $this->monthStart->endOfMonth()->toDateString())
            ->orderByDesc('date')
            ->get();
    }

    /**
     * @param  Collection<int, StudentAttendance>  $days
     * @return array{present: int, late: int, absent: int}
     */
    public function countSummary(Collection $days): array
    {
        return [
            'present' => $days->where('status', StudentAttendance::STATUS_PRESENT)->count(),
            'late' => $days->where('status', StudentAttendance::STATUS_LATE)->count(),
            'absent' => $this->countAbsentDays($days),
        ];
    }

    /** @param Collection<int, StudentAttendance> $days */
    private function countAbsentDays(Collection $days): int
    {
        $schoolYear = SchoolYear::where('is_active', true)->first();

        // the same "enrolled now" rule as every other current-enrollment read
        if (! $schoolYear || ! $this->student->currentEnrollments()->exists()) {
            return 0;
        }

        // the day is not over yet, so today is never absent here
        $yesterday = now()->toImmutable()->subDay()->startOfDay();
        $firstDay = $this->monthStart->max($schoolYear->start_date->toImmutable()->startOfDay());
        $lastDay = $this->monthStart->endOfMonth()->startOfDay()->min($yesterday)->min($schoolYear->end_date->toImmutable()->startOfDay());

        if ($firstDay > $lastDay) {
            return 0;
        }

        $recordedDates = $days->map(fn (StudentAttendance $attendance) => $attendance->date->toDateString());

        return collect(CarbonPeriod::create($firstDay, $lastDay))
            ->filter(fn ($day) => $day->isWeekday() && ! $recordedDates->contains($day->toDateString()))
            ->count();
    }
}
