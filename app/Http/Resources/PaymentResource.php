<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
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
            'ar_number' => $this->ar_number,
            'assessment_id' => $this->assessment_id,
            'school_year_id' => $this->school_year_id,
            'student' => $this->buildStudentSummary($this->student),
            'payment_date' => $this->payment_date->toDateString(),
            'amount' => $this->amount,
            'method' => $this->method,
            'reference_no' => $this->reference_no,
            'remarks' => $this->remarks,
            'balance_after' => $this->balance_after,
            'status' => $this->isVoided() ? Payment::STATUS_VOIDED : Payment::STATUS_VALID,
            'recorded_by' => $this->buildUserSummary($this->recorder),
            'created_at' => $this->created_at,
            'voided_at' => $this->voided_at,
            'voided_by' => $this->buildUserSummary($this->voider),
            'void_reason' => $this->void_reason,
            'can_void' => $this->canBeVoidedBy($request->user()),
        ];
    }
}
