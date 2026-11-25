<?php

namespace App\Http\Resources;

use App\Models\GradingActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GradingActivity
 */
class GradingActivityResource extends JsonResource
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
            'class_id' => $this->class_id,
            'quarter' => $this->quarter,
            'component' => $this->component,
            'title' => $this->title,
            'highest_possible_score' => $this->highest_possible_score,
            'activity_date' => $this->activity_date?->format('Y-m-d'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
