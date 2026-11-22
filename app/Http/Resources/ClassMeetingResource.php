<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\ClassMeeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassMeeting
 */
class ClassMeetingResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day' => $this->day,
            'start_time' => ClassMeeting::formatMinutes($this->start_minutes),
            'end_time' => ClassMeeting::formatMinutes($this->end_minutes),
            'room_id' => $this->room_id,
            'room' => $this->buildRoomSummary($this->room),
        ];
    }
}
