<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Payment;
use Illuminate\Http\Request;

/** @mixin Payment */
class PaymentReceiptResource extends PaymentResource
{
    // a trait's private methods are not inherited, so the subclass imports the summaries itself
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'school_year' => ['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name],
            'grade_level' => $this->buildGradeLevelSummary($this->assessment->gradeLevel),
            'program' => $this->buildProgramSummary($this->assessment->program),
        ];
    }
}
