<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\FeeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array. An unsaved preview draft emits null for the stored-only keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isStored = $this->exists;

        return [
            'id' => $isStored ? $this->id : null,
            'status' => $isStored ? $this->status : null,
            'student_id' => $this->student_id,
            'school_year_id' => $this->school_year_id,
            'grade_level_id' => $this->grade_level_id,
            'program_id' => $this->program_id,
            'payment_plan_id' => $this->payment_plan_id,
            'student' => $this->buildStudentSummary($this->student),
            'school_year' => ['id' => $this->schoolYear->id, 'name' => $this->schoolYear->name],
            'grade_level' => $this->buildGradeLevelSummary($this->gradeLevel),
            'program' => $this->buildProgramSummary($this->program),
            'plan' => $this->buildPlan(),
            'fee_lines' => $this->buildFeeLines(),
            'subtotals' => $this->buildCategoryTotals('gross_'),
            'gross_total' => $this->gross_total,
            'discounts' => $this->buildDiscounts(),
            'discount_totals' => $this->buildCategoryTotals('discount_'),
            'discount_total' => $this->discount_total,
            'net_total' => $this->net_total,
            'schedule' => $this->buildSchedule(),
            'created_by' => $this->buildUserSummary($this->creator),
            'created_at' => $isStored ? $this->created_at : null,
            'superseded_at' => $isStored ? $this->superseded_at : null,
            'superseded_by' => $isStored ? $this->buildUserSummary($this->superseder) : null,
        ];
    }

    /** @return array{name: string, adjustment: array{kind: string, type: string, value: string, amount: string}|null} */
    private function buildPlan(): array
    {
        $adjustment = $this->adjustment_kind === null ? null : [
            'kind' => $this->adjustment_kind,
            'type' => $this->adjustment_type,
            'value' => $this->adjustment_value,
            'amount' => $this->adjustment_amount,
        ];

        return ['name' => $this->plan_name, 'adjustment' => $adjustment];
    }

    /** @return array<string, string> */
    private function buildCategoryTotals(string $prefix): array
    {
        return collect(FeeItem::CATEGORIES)->mapWithKeys(fn (string $category) => [$category => $this->{$prefix.$category}])->all();
    }

    /** @return list<array{name: string, category: string, amount: string}> */
    private function buildFeeLines(): array
    {
        return $this->feeLines->map(fn ($feeLine) => [
            'name' => $feeLine->fee_item_name,
            'category' => $feeLine->category,
            'amount' => $feeLine->amount,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function buildDiscounts(): array
    {
        return $this->discounts->map(fn ($discount) => [
            'name' => $discount->discount_type_name,
            'source' => $discount->source,
            'value_type' => $discount->value_type,
            'value' => $discount->value,
            'base' => $discount->base,
            'reference_no' => $discount->reference_no,
            'computed_amount' => $discount->computed_amount,
            'applied_amount' => $discount->applied_amount,
        ])->all();
    }

    /** @return list<array{sequence: int, label: string, due_date: string, amount: string}> */
    private function buildSchedule(): array
    {
        return $this->installments->map(fn ($installment) => [
            'sequence' => $installment->sequence,
            'label' => $installment->label,
            'due_date' => $installment->due_date->toDateString(),
            'amount' => $installment->amount,
        ])->all();
    }
}
