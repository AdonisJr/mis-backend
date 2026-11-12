<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectionResource extends JsonResource
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
            'school_year_id' => $this->school_year_id,
            'grade_level_id' => $this->grade_level_id,
            'program_id' => $this->program_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'room' => $this->room,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'grade_level' => $this->buildGradeLevelSummary(),
            'program' => $this->buildProgramSummary(),
        ];
    }

    private function buildGradeLevelSummary(): array
    {
        return ['id' => $this->gradeLevel->id, 'name' => $this->gradeLevel->name, 'stage' => $this->gradeLevel->stage];
    }

    private function buildProgramSummary(): ?array
    {
        if (! $this->program) {
            return null;
        }

        return ['id' => $this->program->id, 'code' => $this->program->code, 'name' => $this->program->name];
    }
}
