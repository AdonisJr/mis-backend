<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\ClassMeeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassMeeting
 */
class TimetableMeetingResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schoolClass = $this->schoolClass;
        $subject = $schoolClass->curriculumEntry->subject;

        return [
            'id' => $this->id,
            'day' => $this->day,
            'start_time' => ClassMeeting::formatMinutes($this->start_minutes),
            'end_time' => ClassMeeting::formatMinutes($this->end_minutes),
            'room' => $this->buildRoomSummary($this->room),
            'class' => [
                'id' => $schoolClass->id,
                'term_number' => $schoolClass->term_number,
                'section' => ['id' => $schoolClass->section->id, 'name' => $schoolClass->section->name],
                'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
                'teacher' => $this->buildUserSummary($schoolClass->teacher),
            ],
        ];
    }
}
