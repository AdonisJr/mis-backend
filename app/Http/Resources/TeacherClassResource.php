<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\SchoolClass;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class TeacherClassResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subject = $this->curriculumEntry->subject;

        return [
            'id' => $this->id,
            'school_year' => [
                'id' => $this->schoolYear->id,
                'name' => $this->schoolYear->name,
                'term_type' => $this->schoolYear->term_type,
            ],
            'term_number' => $this->term_number,
            'term_label' => $this->term_number === null ? null : Term::buildLabel($this->schoolYear->term_type, $this->term_number),
            'section' => ['id' => $this->section->id, 'name' => $this->section->name],
            'grade_level' => $this->buildGradeLevelSummary($this->section->gradeLevel),
            'program' => $this->buildProgramSummary($this->section->program),
            'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
            'teacher' => $this->buildUserSummary($this->teacher),
            'meetings' => ClassMeetingResource::collection($this->meetings),
            'student_count' => (int) $this->student_count,
        ];
    }
}
