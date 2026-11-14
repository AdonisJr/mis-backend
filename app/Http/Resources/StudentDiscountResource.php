<?php

namespace App\Http\Resources;

use App\Models\GradeLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentDiscountResource extends JsonResource
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
            'school_year_id' => $this->school_year_id,
            'discount_type_id' => $this->discount_type_id,
            'reference_no' => $this->reference_no,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'student' => $this->buildStudentSummary(),
            'grade_level' => $this->buildGradeLevelSummary(),
            'discount_type' => $this->buildTypeSummary(),
        ];
    }

    /** @return array{id: int, lrn: string, first_name: string, middle_name: string|null, last_name: string} */
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

    /** @return array{id: int, name: string, stage: string}|null */
    private function buildGradeLevelSummary(): ?array
    {
        $gradeLevel = $this->findYearGradeLevel();

        if (! $gradeLevel) {
            return null;
        }

        return ['id' => $gradeLevel->id, 'name' => $gradeLevel->name, 'stage' => $gradeLevel->stage];
    }

    /** @return array{id: int, name: string, source: string, value_type: string, value: string, base: string, is_active: bool} */
    private function buildTypeSummary(): array
    {
        return [
            'id' => $this->discountType->id,
            'name' => $this->discountType->name,
            'source' => $this->discountType->source,
            'value_type' => $this->discountType->value_type,
            'value' => $this->discountType->value,
            'base' => $this->discountType->base,
            'is_active' => $this->discountType->is_active,
        ];
    }

    private function findYearGradeLevel(): ?GradeLevel
    {
        // studentEnrollments is ordered latest first, so the first match is the latest enrollment of this school year
        $yearEnrollment = $this->studentEnrollments->first(fn ($enrollment) => $enrollment->section->school_year_id === $this->school_year_id);

        return $yearEnrollment?->section->gradeLevel;
    }
}
