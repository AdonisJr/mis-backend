<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\Payment;
use App\Support\UnpostedEventFinder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps one hydrated event array from UnpostedEventFinder. */
class UnpostedEventResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource['record'];

        return [
            'type' => $this->resource['type'],
            'source_id' => $record->id,
            'date' => $this->resource['date'],
            'student' => $this->buildStudentSummary($record->student),
            'amount' => $record instanceof Payment ? $record->amount : $record->net_total,
            'reason' => $this->resource['reason'],
            'reason_message' => UnpostedEventFinder::REASON_MESSAGES[$this->resource['reason']],
        ];
    }
}
