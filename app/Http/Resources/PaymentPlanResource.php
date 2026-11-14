<?php

namespace App\Http\Resources;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentPlanResource extends JsonResource
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
            'school_year_id' => $this->school_year_id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'installments_count' => $this->installments->count(),
            'installments' => $this->buildInstallmentRows(),
            'down_payment' => $this->buildDownPayment(),
            'adjustment' => $this->buildAdjustment(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return list<array{sequence: int, due_date: string}> */
    private function buildInstallmentRows(): array
    {
        return $this->installments
            ->map(fn (PaymentPlanInstallment $installment) => ['sequence' => $installment->sequence, 'due_date' => $installment->due_date->toDateString()])
            ->all();
    }

    /** @return array{type: string, value: string}|null */
    private function buildDownPayment(): ?array
    {
        if ($this->down_payment_type === null) {
            return null;
        }

        return ['type' => $this->down_payment_type, 'value' => $this->down_payment_value];
    }

    /** @return array{kind: string, type: string, value: string, category: string}|null */
    private function buildAdjustment(): ?array
    {
        if ($this->adjustment_kind === null) {
            return null;
        }

        return [
            'kind' => $this->adjustment_kind,
            'type' => $this->adjustment_type,
            'value' => $this->adjustment_value,
            'category' => PaymentPlan::ADJUSTMENT_CATEGORY,
        ];
    }
}
