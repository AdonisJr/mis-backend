<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CurriculumEntryResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    private const SUBJECT_SUMMARY_KEYS = ['id', 'code', 'name', 'type', 'weekly_hours', 'is_active'];

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
            'subject_id' => $this->subject_id,
            'program_id' => $this->program_id,
            'term_number' => $this->term_number,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'subject' => Arr::only(SubjectResource::make($this->subject)->resolve(), self::SUBJECT_SUMMARY_KEYS),
            'grade_level' => $this->buildGradeLevelSummary($this->gradeLevel),
            'program' => $this->buildProgramSummary($this->program),
        ];
    }
}
