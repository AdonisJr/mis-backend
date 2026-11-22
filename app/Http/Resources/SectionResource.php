<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectionResource extends JsonResource
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
            'school_year_id' => $this->school_year_id,
            'grade_level_id' => $this->grade_level_id,
            'program_id' => $this->program_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'room_id' => $this->room_id,
            'adviser_id' => $this->adviser_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'grade_level' => $this->buildGradeLevelSummary($this->gradeLevel),
            'program' => $this->buildProgramSummary($this->program),
            'room' => $this->buildRoomSummary($this->room),
            'adviser' => $this->buildUserSummary($this->adviser),
            'enrolled_count' => $this->enrolled_count,
        ];
    }
}
