<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps an Enrollment selected by TodayAttendanceRoster::buildRowQuery.
 */
class TodayAttendanceRowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attendance_id' => $this->attendance_id,
            'student' => [
                'id' => $this->student->id,
                'lrn' => $this->student->lrn,
                'first_name' => $this->student->first_name,
                'middle_name' => $this->student->middle_name,
                'last_name' => $this->student->last_name,
            ],
            'grade_level' => ['id' => $this->section->gradeLevel->id, 'name' => $this->section->gradeLevel->name],
            'section' => ['id' => $this->section->id, 'name' => $this->section->name],
            'time_in' => $this->time_in,
            'time_out' => $this->time_out,
            'status' => $this->attendance_status,
        ];
    }
}
