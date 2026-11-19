<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentRowResource extends JsonResource
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
            'status' => $this->status,
            'payment_plan_id' => $this->payment_plan_id,
            'plan_name' => $this->plan_name,
            'net_total' => $this->net_total,
            'student' => $this->buildStudentSummary($this->student),
            'grade_level' => $this->buildGradeLevelSummary($this->gradeLevel),
            'program' => $this->buildProgramSummary($this->program),
            'created_by' => $this->buildUserSummary($this->creator),
            'created_at' => $this->created_at,
            'superseded_at' => $this->superseded_at,
            'superseded_by' => $this->buildUserSummary($this->superseder),
            'school_year_id' => $this->school_year_id,
        ];
    }
}
