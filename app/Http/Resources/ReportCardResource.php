<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array that App\Support\ReportCardBuilder::build() returns.
 *
 * @property array{source: Enrollment, subjects: list<array<string, mixed>>, general_averages: list<array<string, mixed>>} $resource
 */
class ReportCardResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = $this->resource['source'];
        $section = $source->section;

        return [
            'student' => $this->buildStudentSummary($source->student),
            'school_year' => ['id' => $section->schoolYear->id, 'name' => $section->schoolYear->name, 'term_type' => $section->schoolYear->term_type],
            'grade_level' => $this->buildGradeLevelSummary($section->gradeLevel),
            'program' => $this->buildProgramSummary($section->program),
            'section' => ['id' => $section->id, 'name' => $section->name],
            'adviser' => $this->buildUserSummary($section->adviser),
            'subjects' => $this->resource['subjects'],
            'general_averages' => $this->resource['general_averages'],
        ];
    }
}
