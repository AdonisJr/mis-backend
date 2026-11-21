<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\PromissoryNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps one row array built by `PaymentHoldList`. */
class PaymentHoldRowResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schoolYear = $this->resource['school_year'];

        return [
            'student_id' => $this->resource['student']->id,
            'school_year_id' => $schoolYear->id,
            'student' => $this->buildStudentSummary($this->resource['student']),
            'school_year' => ['id' => $schoolYear->id, 'name' => $schoolYear->name],
            'balance' => (string) $this->resource['balance'],
            'note_status' => $this->resource['note_status'],
            'note' => $this->buildNote($this->resource['note']),
        ];
    }

    /** @return array<string, mixed>|null */
    private function buildNote(?PromissoryNote $note): ?array
    {
        if ($note === null) {
            return null;
        }

        return [
            'id' => $note->id,
            'promise_date' => $note->promise_date->toDateString(),
            'promised_amount' => $note->promised_amount,
            'granted_by' => $this->buildUserSummary($note->granter),
            'granted_at' => $note->created_at,
        ];
    }
}
