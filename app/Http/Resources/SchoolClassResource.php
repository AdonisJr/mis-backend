<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\SchoolClass;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    private const HOURS_SCALE = 2;

    private const MINUTES_PER_HOUR = 60;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subject = $this->curriculumEntry->subject;
        $scheduledMinutes = $this->countScheduledMinutes();

        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'curriculum_entry_id' => $this->curriculum_entry_id,
            'teacher_id' => $this->teacher_id,
            'school_year_id' => $this->school_year_id,
            'term_number' => $this->term_number,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'section' => ['id' => $this->section->id, 'name' => $this->section->name],
            'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
            'teacher' => $this->buildUserSummary($this->teacher),
            'meetings' => ClassMeetingResource::collection($this->meetings),
            'required_weekly_hours' => $this->formatHours(BigDecimal::of($subject->weekly_hours)),
            'scheduled_weekly_minutes' => $scheduledMinutes,
            'scheduled_weekly_hours' => $this->formatHours(BigDecimal::of($scheduledMinutes)->dividedBy(self::MINUTES_PER_HOUR, self::HOURS_SCALE, RoundingMode::HALF_UP)),
            'hours_status' => $this->deriveHoursStatus(),
        ];
    }

    private function formatHours(BigDecimal $hours): string
    {
        return (string) $hours->toScale(self::HOURS_SCALE, RoundingMode::HALF_UP);
    }
}
