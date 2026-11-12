<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'section_id' => $this->section_id,
            'term_id' => $this->term_id,
            'status' => $this->status,
            'enrolled_at' => $this->enrolled_at->toDateString(),
            'ended_at' => $this->ended_at?->toDateString(),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'student' => $this->buildStudentSummary(),
            'section' => $this->buildSectionSummary(),
            'term' => $this->buildTermSummary(),
        ];
    }

    private function buildStudentSummary(): array
    {
        return [
            'id' => $this->student->id,
            'lrn' => $this->student->lrn,
            'first_name' => $this->student->first_name,
            'middle_name' => $this->student->middle_name,
            'last_name' => $this->student->last_name,
        ];
    }

    private function buildSectionSummary(): array
    {
        return [
            'id' => $this->section->id,
            'name' => $this->section->name,
            'grade_level' => ['id' => $this->section->gradeLevel->id, 'name' => $this->section->gradeLevel->name, 'stage' => $this->section->gradeLevel->stage],
            'program' => $this->section->program ? ['id' => $this->section->program->id, 'code' => $this->section->program->code] : null,
        ];
    }

    private function buildTermSummary(): ?array
    {
        if (! $this->term) {
            return null;
        }

        return ['id' => $this->term->id, 'term_number' => $this->term->term_number, 'label' => $this->term->getLabel()];
    }
}
