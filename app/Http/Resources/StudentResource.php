<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
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
            'lrn' => $this->lrn,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date->toDateString(),
            'gender' => $this->gender,
            'address' => $this->address,
            'contact' => $this->contact,
            'guardian_name' => $this->guardian_name,
            'guardian_relationship' => $this->guardian_relationship,
            'guardian_contact' => $this->guardian_contact,
            'rfid' => $this->rfid,
            'current_enrollment' => $this->buildCurrentEnrollmentSummary(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function buildCurrentEnrollmentSummary(): ?array
    {
        $currentEnrollment = $this->currentEnrollments->sortByDesc('id')->first();

        if (! $currentEnrollment) {
            return null;
        }

        return [
            'id' => $currentEnrollment->id,
            'section_id' => $currentEnrollment->section_id,
            'term_id' => $currentEnrollment->term_id,
            'section' => ['id' => $currentEnrollment->section->id, 'name' => $currentEnrollment->section->name],
            'grade_level' => ['id' => $currentEnrollment->section->gradeLevel->id, 'name' => $currentEnrollment->section->gradeLevel->name, 'stage' => $currentEnrollment->section->gradeLevel->stage],
        ];
    }
}
