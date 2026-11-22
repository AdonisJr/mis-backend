<?php

namespace App\Http\Resources;

use App\Models\CurriculumEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CurriculumEntry
 */
class SectionTimetableRowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scheduledClass = $this->schoolClasses->first();

        return [
            'curriculum_entry_id' => $this->id,
            'sort_order' => $this->sort_order,
            'subject' => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
                'type' => $this->subject->type,
                'weekly_hours' => $this->subject->weekly_hours,
            ],
            'class' => $scheduledClass ? SchoolClassResource::make($scheduledClass) : null,
        ];
    }
}
