<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Assessment;
use App\Support\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Assessment */
class PaymentLedgerResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $allocation = new PaymentAllocation($this->resource);

        return [
            'assessment_id' => $this->id,
            'student' => $this->buildStudentSummary($this->student),
            'school_year' => ['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name],
            'grade_level' => $this->buildGradeLevelSummary($this->gradeLevel),
            'program' => $this->buildProgramSummary($this->program),
            'plan_name' => $this->plan_name,
            'net_total' => $this->net_total,
            'total_paid' => (string) $this->calculateTotalPaid(),
            'balance' => (string) $this->calculateBalance(),
            'next_amount_due' => (string) $allocation->calculateNextAmountDue(),
            'schedule' => $allocation->buildScheduleRows(),
            'payments' => PaymentResource::collection($this->payments),
            'promissory_notes' => PromissoryNoteResource::collection($this->promissoryNotes),
        ];
    }
}
