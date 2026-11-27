<?php

namespace App\Support;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceScanRecorder
{
    private const TRANSACTION_ATTEMPTS = 3;

    private const DEFAULT_LATE_AFTER = '07:30';

    // `H` takes 07:30 and `G` takes 7:30; Carbon's round-trip check accepts only one of them per format
    private const CUTOFF_INPUT_FORMATS = ['!H:i', '!G:i'];

    private const TIME_FORMAT = 'H:i:s';

    private readonly CarbonImmutable $scannedAt;

    public function __construct(private readonly Student $student, private readonly SchoolYear $schoolYear)
    {
        // whole seconds, because the stored time columns carry none and the debounce compares against them
        $this->scannedAt = now()->toImmutable()->startOfSecond();
    }

    public function record(): StudentAttendance
    {
        return DB::transaction(fn () => $this->recordWithinLock(), self::TRANSACTION_ATTEMPTS);
    }

    private function recordWithinLock(): StudentAttendance
    {
        // the student row is the lock target, so two first taps of one card serialise and the second one is debounced
        Student::whereKey($this->student->id)->lockForUpdate()->first();

        $attendance = $this->student->attendances()->whereDate('date', $this->scannedAt->toDateString())->first();

        if (! $attendance) {
            return $this->createTimeIn();
        }

        if ($this->isWithinDebounce($attendance)) {
            return $attendance;
        }

        $attendance->update(['time_out' => $this->scannedAt->format(self::TIME_FORMAT)]);

        return $attendance;
    }

    private function createTimeIn(): StudentAttendance
    {
        $attendance = new StudentAttendance([
            'student_id' => $this->student->id,
            'school_year_id' => $this->schoolYear->id,
            'date' => $this->scannedAt->toDateString(),
            'time_in' => $this->scannedAt->format(self::TIME_FORMAT),
        ]);
        $attendance->forceFill(['status' => $this->resolveStatus()])->save();

        return $attendance;
    }

    private function isWithinDebounce(StudentAttendance $attendance): bool
    {
        $lastScan = CarbonImmutable::parse($attendance->date->toDateString().' '.($attendance->time_out ?? $attendance->time_in));

        return $lastScan->diffInSeconds($this->scannedAt, true) < config('attendance.debounce_seconds');
    }

    private function resolveStatus(): string
    {
        $lateAfter = $this->parseLateCutoff()->format(self::TIME_FORMAT);

        return $this->scannedAt->format(self::TIME_FORMAT) > $lateAfter
            ? StudentAttendance::STATUS_LATE
            : StudentAttendance::STATUS_PRESENT;
    }

    private function parseLateCutoff(): CarbonImmutable
    {
        $configured = (string) config('attendance.late_after');

        foreach (self::CUTOFF_INPUT_FORMATS as $inputFormat) {
            if (CarbonImmutable::canBeCreatedFromFormat($configured, $inputFormat)) {
                return CarbonImmutable::createFromFormat($inputFormat, $configured);
            }
        }

        return CarbonImmutable::createFromFormat(self::CUTOFF_INPUT_FORMATS[0], self::DEFAULT_LATE_AFTER);
    }
}
