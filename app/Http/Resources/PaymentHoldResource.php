<?php

namespace App\Http\Resources;

use App\Models\PromissoryNote;
use App\Support\PaymentHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentHold */
class PaymentHoldResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->getStudentId(),
            'school_year_id' => $this->getTargetYear()->id,
            'on_hold' => $this->isOnHold(),
            'unpaid_years' => array_map(fn (array $unpaidYear) => $this->buildUnpaidYear($unpaidYear), $this->listUnpaidYears()),
        ];
    }

    /** @return array<string, mixed> */
    private function buildUnpaidYear(array $unpaidYear): array
    {
        $assessment = $unpaidYear['assessment'];

        return [
            'school_year' => ['id' => $assessment->schoolYear->id, 'name' => $assessment->schoolYear->name],
            'net_total' => $assessment->net_total,
            'total_paid' => (string) $assessment->calculateTotalPaid(),
            'balance' => (string) $unpaidYear['balance'],
            'active_note' => $this->buildActiveNote($unpaidYear['active_note']),
        ];
    }

    /** @return array{id: int, promise_date: string, promised_amount: string}|null */
    private function buildActiveNote(?PromissoryNote $activeNote): ?array
    {
        if ($activeNote === null) {
            return null;
        }

        return [
            'id' => $activeNote->id,
            'promise_date' => $activeNote->promise_date->toDateString(),
            'promised_amount' => $activeNote->promised_amount,
        ];
    }
}
