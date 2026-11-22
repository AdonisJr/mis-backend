<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\StoreSectionRequest;
use App\Models\ClassMeeting;
use App\Models\Room;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

trait ValidatesClassMeetings
{
    // bounds the per-meeting conflict queries; 30 meetings of 80 minutes cover the 40-hour subject maximum
    public const MEETINGS_MAX = 30;

    public const TIME_RULE = 'regex:/\A([01][0-9]|2[0-3]):[0-5][0-9]\z/';

    public const TIME_STEP_MESSAGE = 'The time must be on a 5-minute step.';

    public const TIME_BOUNDS_MESSAGE = 'The time must be between %s and %s.';

    public const END_BEFORE_START_MESSAGE = 'The end time must be after the start time.';

    public const MEETING_OVERLAP_MESSAGE = 'This meeting overlaps another meeting of this class on the same day.';

    private const TIME_FIELDS = ['start_time', 'end_time'];

    abstract protected function findTargetSection(): ?Section;

    abstract protected function findStoredRoomIds(): Collection;

    /** @return array<string, array<int, mixed>> */
    public function buildMeetingRules(): array
    {
        return [
            'meetings' => ['present', 'array', 'list', 'max:'.self::MEETINGS_MAX],
            'meetings.*' => ['array'],
            'meetings.*.day' => ['bail', 'required', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'between:'.ClassMeeting::DAY_MONDAY.','.ClassMeeting::DAY_SATURDAY],
            'meetings.*.start_time' => ['bail', 'required', 'string', self::TIME_RULE],
            'meetings.*.end_time' => ['bail', 'required', 'string', self::TIME_RULE],
            'meetings.*.room_id' => StoreSectionRequest::buildIdRules('nullable', Room::class),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function buildMeetingChecks(): array
    {
        return [
            fn (Validator $validator) => $this->rejectOffStepTimes($validator),
            fn (Validator $validator) => $this->rejectOutOfBoundsTimes($validator),
            fn (Validator $validator) => $this->rejectEndBeforeStart($validator),
            fn (Validator $validator) => $this->rejectOverlappingMeetings($validator),
            fn (Validator $validator) => $this->rejectInactiveRooms($validator),
        ];
    }

    /** @return list<array{day: int, start_minutes: int, end_minutes: int, room_id: int|null}> */
    public function buildMeetingRows(): array
    {
        $meetingRows = [];

        foreach ($this->validated('meetings') as $submittedMeeting) {
            $meetingRows[] = [
                'day' => (int) $submittedMeeting['day'],
                'start_minutes' => ClassMeeting::convertToMinutes($submittedMeeting['start_time']),
                'end_minutes' => ClassMeeting::convertToMinutes($submittedMeeting['end_time']),
                'room_id' => $this->resolveRoomId($submittedMeeting['room_id'] ?? null),
            ];
        }

        return $meetingRows;
    }

    protected function resolveRoomId(mixed $submittedRoomId): ?int
    {
        if ($submittedRoomId !== null && $submittedRoomId !== '') {
            return (int) $submittedRoomId;
        }

        return $this->findTargetSection()?->room_id;
    }

    private function hasMeetingErrors(Validator $validator): bool
    {
        return collect($validator->errors()->keys())->contains(fn (string $errorKey) => str_starts_with($errorKey, 'meetings'));
    }

    /** @return array<int, int> minutes of one time field, keyed by submitted meeting index */
    private function collectMinutes(string $timeField): array
    {
        return collect($this->input('meetings'))->map(fn (array $meeting) => ClassMeeting::convertToMinutes($meeting[$timeField]))->all();
    }

    private function rejectOffStepTimes(Validator $validator): void
    {
        if ($this->hasMeetingErrors($validator)) {
            return;
        }

        foreach (self::TIME_FIELDS as $timeField) {
            $this->rejectOffStepField($validator, $timeField);
        }
    }

    private function rejectOffStepField(Validator $validator, string $timeField): void
    {
        foreach ($this->collectMinutes($timeField) as $meetingIndex => $minutes) {
            if ($minutes % ClassMeeting::MINUTE_STEP !== 0) {
                $validator->errors()->add("meetings.{$meetingIndex}.{$timeField}", self::TIME_STEP_MESSAGE);
            }
        }
    }

    private function rejectOutOfBoundsTimes(Validator $validator): void
    {
        if ($this->hasMeetingErrors($validator)) {
            return;
        }

        $endMinutes = $this->collectMinutes('end_time');
        $boundsMessage = sprintf(self::TIME_BOUNDS_MESSAGE, ClassMeeting::SCHOOL_DAY_START, ClassMeeting::SCHOOL_DAY_END);

        foreach ($this->collectMinutes('start_time') as $meetingIndex => $startMinutes) {
            // a meeting out on both sides reports on the end only
            $boundsField = match (true) {
                $this->isOutOfBounds($endMinutes[$meetingIndex]) => 'end_time',
                $this->isOutOfBounds($startMinutes) => 'start_time',
                default => null,
            };

            if ($boundsField !== null) {
                $validator->errors()->add("meetings.{$meetingIndex}.{$boundsField}", $boundsMessage);
            }
        }
    }

    private function isOutOfBounds(int $minutes): bool
    {
        return $minutes < ClassMeeting::convertToMinutes(ClassMeeting::SCHOOL_DAY_START) || $minutes > ClassMeeting::convertToMinutes(ClassMeeting::SCHOOL_DAY_END);
    }

    private function rejectEndBeforeStart(Validator $validator): void
    {
        if ($this->hasMeetingErrors($validator)) {
            return;
        }

        $startMinutes = $this->collectMinutes('start_time');

        foreach ($this->collectMinutes('end_time') as $meetingIndex => $endMinutes) {
            if ($endMinutes <= $startMinutes[$meetingIndex]) {
                $validator->errors()->add("meetings.{$meetingIndex}.end_time", self::END_BEFORE_START_MESSAGE);
            }
        }
    }

    private function rejectOverlappingMeetings(Validator $validator): void
    {
        if ($this->hasMeetingErrors($validator)) {
            return;
        }

        $startMinutes = $this->collectMinutes('start_time');
        $endMinutes = $this->collectMinutes('end_time');
        $meetings = $this->input('meetings');
        $latestEndByDay = [];

        foreach ($this->sortMeetingIndexes($startMinutes) as $meetingIndex) {
            $day = (int) $meetings[$meetingIndex]['day'];

            if ($startMinutes[$meetingIndex] < ($latestEndByDay[$day] ?? 0)) {
                $validator->errors()->add("meetings.{$meetingIndex}", self::MEETING_OVERLAP_MESSAGE);
            }

            $latestEndByDay[$day] = max($latestEndByDay[$day] ?? 0, $endMinutes[$meetingIndex]);
        }
    }

    /**
     * @param  array<int, int>  $startMinutes
     * @return list<int> submitted meeting indexes ordered by day, start and index
     */
    private function sortMeetingIndexes(array $startMinutes): array
    {
        $meetings = $this->input('meetings');
        $sortedIndexes = array_keys($meetings);
        usort($sortedIndexes, fn (int $first, int $second) => [(int) $meetings[$first]['day'], $startMinutes[$first], $first] <=> [(int) $meetings[$second]['day'], $startMinutes[$second], $second]);

        return $sortedIndexes;
    }

    private function rejectInactiveRooms(Validator $validator): void
    {
        $targetSection = $this->findTargetSection();

        if ($this->hasMeetingErrors($validator) || ! $targetSection) {
            return;
        }

        $storedRoomIds = $this->findStoredRoomIds();

        foreach ($this->input('meetings') as $meetingIndex => $meeting) {
            $roomId = $this->resolveRoomId($meeting['room_id'] ?? null);

            if ($this->isRoomBlocked($roomId, $storedRoomIds)) {
                $validator->errors()->add("meetings.{$meetingIndex}.room_id", StoreSectionRequest::ROOM_INACTIVE_MESSAGE);
            }
        }
    }

    /** @param Collection<int, int> $storedRoomIds */
    private function isRoomBlocked(?int $roomId, Collection $storedRoomIds): bool
    {
        if ($roomId === null || $storedRoomIds->contains($roomId)) {
            return false;
        }

        return Room::whereKey($roomId)->where('is_active', false)->exists();
    }
}
