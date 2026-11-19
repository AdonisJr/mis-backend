<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentCandidateResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sourceSection = Assessment::pickSourceEnrollment($this->enrollments)->section;

        return [
            'id' => $this->id,
            'lrn' => $this->lrn,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'grade_level' => $this->buildGradeLevelSummary($sourceSection->gradeLevel),
            'program' => $this->buildProgramSummary($sourceSection->program),
        ];
    }
}
