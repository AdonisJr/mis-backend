<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a StudentAttendance with student.currentEnrollments.section.gradeLevel loaded.
 */
class AttendanceRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentEnrollment = $this->student->currentEnrollments->sortByDesc('id')->first();

        return [
            'attendance_id' => $this->id,
            'date' => $this->date->toDateString(),
            'student' => [
                'id' => $this->student->id,
                'lrn' => $this->student->lrn,
                'first_name' => $this->student->first_name,
                'middle_name' => $this->student->middle_name,
                'last_name' => $this->student->last_name,
            ],
            'grade_level' => $currentEnrollment ? ['id' => $currentEnrollment->section->gradeLevel->id, 'name' => $currentEnrollment->section->gradeLevel->name] : null,
            'section' => $currentEnrollment ? ['id' => $currentEnrollment->section->id, 'name' => $currentEnrollment->section->name] : null,
            'time_in' => $this->time_in,
            'time_out' => $this->time_out,
            'status' => $this->status,
        ];
    }
}
