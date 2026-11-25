<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\ClassQuarterEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassQuarterEvent
 */
class ClassQuarterEventResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'reason' => $this->reason,
            'user' => $this->buildUserSummary($this->user),
            'created_at' => $this->created_at,
        ];
    }
}
