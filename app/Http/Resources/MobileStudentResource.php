<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileStudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentEnrollment = $this->currentEnrollments->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'lrn' => $this->lrn,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'grade_level' => $currentEnrollment ? $currentEnrollment->section->gradeLevel->only(['id', 'name', 'stage']) : null,
            'section' => $currentEnrollment ? $currentEnrollment->section->only(['id', 'name']) : null,
            'school_year' => $currentEnrollment ? $currentEnrollment->section->schoolYear->only(['id', 'name']) : null,
        ];
    }
}
