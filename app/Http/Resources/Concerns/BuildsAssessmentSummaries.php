<?php

namespace App\Http\Resources\Concerns;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\Room;
use App\Models\Student;
use App\Models\User;

trait BuildsAssessmentSummaries
{
    /** @return array{id: int, lrn: string, first_name: string, middle_name: string|null, last_name: string} */
    private function buildStudentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'lrn' => $student->lrn,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
        ];
    }

    /** @return array{id: int, name: string, stage: string} */
    private function buildGradeLevelSummary(GradeLevel $gradeLevel): array
    {
        return ['id' => $gradeLevel->id, 'name' => $gradeLevel->name, 'stage' => $gradeLevel->stage];
    }

    /** @return array{id: int, code: string, name: string}|null */
    private function buildProgramSummary(?Program $program): ?array
    {
        return $program ? ['id' => $program->id, 'code' => $program->code, 'name' => $program->name] : null;
    }

    /** @return array{id: int, name: string}|null */
    private function buildUserSummary(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    /** @return array{id: int, name: string, building: string|null}|null */
    private function buildRoomSummary(?Room $room): ?array
    {
        return $room ? ['id' => $room->id, 'name' => $room->name, 'building' => $room->building] : null;
    }
}
