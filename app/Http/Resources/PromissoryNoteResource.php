<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\PromissoryNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PromissoryNote */
class PromissoryNoteResource extends JsonResource
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
            'student_id' => $this->student_id,
            'school_year_id' => $this->school_year_id,
            'student' => $this->buildStudentSummary($this->student),
            'school_year' => ['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name],
            'promised_amount' => $this->promised_amount,
            'promise_date' => $this->promise_date->toDateString(),
            'remarks' => $this->remarks,
            'status' => $this->deriveStatus(),
            'granted_by' => $this->buildUserSummary($this->granter),
            'granted_at' => $this->created_at,
            'cancelled_by' => $this->buildUserSummary($this->canceller),
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
