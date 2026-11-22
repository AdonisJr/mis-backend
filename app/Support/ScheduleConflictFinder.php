<?php

namespace App\Support;

use App\Models\ClassMeeting;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;

class ScheduleConflictFinder
{
    public const CONFLICT_MESSAGE = '%s conflict: %s %s meets on %s %s-%s.';

    public const KIND_TEACHER = 'Teacher';

    public const KIND_ROOM = 'Room';

    public const KIND_SECTION = 'Section';

    public function __construct(private readonly SchoolClass $schoolClass) {}

    /** @param array{day: int, start_minutes: int, end_minutes: int, room_id: int|null} $meetingRow */
    public function findConflict(array $meetingRow): ?ClassMeeting
    {
        $conflictQuery = ClassMeeting::query()
            ->select('class_meetings.*')
            ->join('classes', 'classes.id', '=', 'class_meetings.class_id')
            ->where('class_meetings.day', $meetingRow['day'])
            ->where('class_meetings.start_minutes', '<', $meetingRow['end_minutes'])
            ->where('class_meetings.end_minutes', '>', $meetingRow['start_minutes']);

        $this->applyPeriodFilter($conflictQuery);
        $this->applyOwnerFilter($conflictQuery, $meetingRow);

        return $conflictQuery
            ->with('schoolClass.section', 'schoolClass.curriculumEntry.subject')
            ->orderBy('class_meetings.start_minutes')
            ->orderBy('class_meetings.id')
            ->first();
    }

    public function formatConflictMessage(ClassMeeting $clashingMeeting, ?int $roomId): string
    {
        $clashingClass = $clashingMeeting->schoolClass;

        $conflictKind = match (true) {
            $clashingClass->teacher_id === $this->schoolClass->teacher_id => self::KIND_TEACHER,
            $roomId !== null && $clashingMeeting->room_id === $roomId => self::KIND_ROOM,
            default => self::KIND_SECTION,
        };

        return sprintf(
            self::CONFLICT_MESSAGE,
            $conflictKind,
            $clashingClass->section->name,
            $clashingClass->curriculumEntry->subject->code,
            ClassMeeting::DAY_LABELS[$clashingMeeting->day],
            ClassMeeting::formatMinutes($clashingMeeting->start_minutes),
            ClassMeeting::formatMinutes($clashingMeeting->end_minutes),
        );
    }

    /** @param Builder<ClassMeeting> $conflictQuery */
    private function applyPeriodFilter(Builder $conflictQuery): void
    {
        $termNumber = $this->schoolClass->term_number;

        $conflictQuery->where('classes.school_year_id', $this->schoolClass->school_year_id)
            ->when($this->schoolClass->exists, fn (Builder $query) => $query->where('classes.id', '!=', $this->schoolClass->id))
            ->when($termNumber !== null, fn (Builder $query) => $query->where(fn (Builder $termQuery) => $termQuery->whereNull('classes.term_number')->orWhere('classes.term_number', $termNumber)));
    }

    /**
     * @param  Builder<ClassMeeting>  $conflictQuery
     * @param  array{day: int, start_minutes: int, end_minutes: int, room_id: int|null}  $meetingRow
     */
    private function applyOwnerFilter(Builder $conflictQuery, array $meetingRow): void
    {
        $conflictQuery->where(function (Builder $ownerQuery) use ($meetingRow) {
            $ownerQuery->where('classes.teacher_id', $this->schoolClass->teacher_id)
                ->orWhere('classes.section_id', $this->schoolClass->section_id)
                ->when($meetingRow['room_id'] !== null, fn (Builder $query) => $query->orWhere('class_meetings.room_id', $meetingRow['room_id']));
        });
    }
}
