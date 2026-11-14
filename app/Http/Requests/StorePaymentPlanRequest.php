<?php

namespace App\Http\Requests;

use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentPlanRequest extends FormRequest
{
    public const NAME_TAKEN_MESSAGE = 'The name has already been taken for this school year.';

    public const DUE_DATES_ORDER_MESSAGE = 'Each due date must be after the previous one.';

    public const DUE_DATE_OUTSIDE_YEAR_MESSAGE = 'The due date must be within the school year.';

    public const DOWN_PAYMENT_REQUIRED_MESSAGE = 'A plan with installments needs a down payment.';

    public const DOWN_PAYMENT_FORBIDDEN_MESSAGE = 'A plan without installments cannot have a down payment.';

    public const DISCOUNT_NOT_ALLOWED_MESSAGE = 'A discount is only allowed on a plan without installments.';

    public const SURCHARGE_NOT_ALLOWED_MESSAGE = 'A surcharge is only allowed on a plan with installments.';

    private ?SchoolYear $submittedSchoolYear = null;

    private bool $isSubmittedSchoolYearLoaded = false;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Build the value rules for a down payment or adjustment by reading its submitted type.
     *
     * @return list<string>
     */
    protected function buildValueRules(string $typeKey, string $percentLimitRule): array
    {
        $valueRules = ['bail', 'numeric', 'decimal:0,2', 'gt:0'];
        $submittedType = $this->input($typeKey);

        if ($submittedType === PaymentPlan::TYPE_FIXED) {
            $valueRules[] = 'max:'.StoreFeeScheduleRequest::AMOUNT_MAX;
        }

        if ($submittedType === PaymentPlan::TYPE_PERCENT) {
            $valueRules[] = $percentLimitRule;
        }

        return $valueRules;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_dates' => ['present', 'array', 'list', 'max:'.PaymentPlan::MAX_INSTALLMENTS],
            'due_dates.*' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'distinct'],
            // child rules carry no `required`: it is implicit and would fire when the parent is null
            'down_payment' => ['nullable', 'array', 'required_array_keys:type,value'],
            'down_payment.type' => ['bail', 'string', Rule::in(PaymentPlan::DOWN_PAYMENT_TYPES)],
            'down_payment.value' => $this->buildValueRules('down_payment.type', 'lt:100'),
            'adjustment' => ['nullable', 'array', 'required_array_keys:kind,type,value'],
            'adjustment.kind' => ['bail', 'string', Rule::in(PaymentPlan::ADJUSTMENT_KINDS)],
            'adjustment.type' => ['bail', 'string', Rule::in(PaymentPlan::ADJUSTMENT_TYPES)],
            'adjustment.value' => $this->buildValueRules('adjustment.type', 'max:100'),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTakenName($validator),
            fn (Validator $validator) => $this->rejectInvalidDueDates($validator),
            fn (Validator $validator) => $this->rejectDownPaymentMismatch($validator),
            fn (Validator $validator) => $this->rejectAdjustmentMismatch($validator),
        ];
    }

    public function buildNameCandidate(): PaymentPlan
    {
        return new PaymentPlan(['school_year_id' => (int) $this->input('school_year_id'), 'name' => $this->input('name')]);
    }

    /** @return array<string, mixed> */
    public function buildPlanAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'down_payment_type' => data_get($validated, 'down_payment.type'),
            'down_payment_value' => data_get($validated, 'down_payment.value'),
            'adjustment_kind' => data_get($validated, 'adjustment.kind'),
            'adjustment_type' => data_get($validated, 'adjustment.type'),
            'adjustment_value' => data_get($validated, 'adjustment.value'),
        ];
    }

    /** @return list<array{sequence: int, due_date: string}> */
    public function buildDueDateRows(): array
    {
        $dueDateRows = [];

        foreach ($this->validated('due_dates') as $dueDateIndex => $dueDate) {
            $dueDateRows[] = ['sequence' => $dueDateIndex + 1, 'due_date' => $dueDate];
        }

        return $dueDateRows;
    }

    protected function findPlanSchoolYear(): ?SchoolYear
    {
        if (! $this->isSubmittedSchoolYearLoaded) {
            $this->submittedSchoolYear = SchoolYear::find($this->input('school_year_id'));
            $this->isSubmittedSchoolYearLoaded = true;
        }

        return $this->submittedSchoolYear;
    }

    private function rejectTakenName(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['name', 'school_year_id']) || ! $this->findPlanSchoolYear()) {
            return;
        }

        if (! PaymentPlan::withSameName($this->buildNameCandidate())->exists()) {
            return;
        }

        $validator->errors()->add('name', self::NAME_TAKEN_MESSAGE);
    }

    private function rejectInvalidDueDates(Validator $validator): void
    {
        // decided once, before either rule adds an error, so an order error does not hide a range error
        if ($validator->errors()->has('due_dates') || $validator->errors()->has('due_dates.*')) {
            return;
        }

        $schoolYear = $validator->errors()->has('school_year_id') ? null : $this->findPlanSchoolYear();

        $this->rejectUnorderedDueDates($validator);
        $this->rejectDueDatesOutsideYear($validator, $schoolYear);
    }

    private function rejectUnorderedDueDates(Validator $validator): void
    {
        $dueDates = $this->input('due_dates');

        foreach ($dueDates as $dueDateIndex => $dueDate) {
            if ($dueDateIndex > 0 && $dueDate <= $dueDates[$dueDateIndex - 1]) {
                $validator->errors()->add('due_dates.'.$dueDateIndex, self::DUE_DATES_ORDER_MESSAGE);
            }
        }
    }

    private function rejectDueDatesOutsideYear(Validator $validator, ?SchoolYear $schoolYear): void
    {
        if (! $schoolYear) {
            return;
        }

        foreach ($this->input('due_dates') as $dueDateIndex => $dueDate) {
            if (! $schoolYear->containsDate($dueDate)) {
                $validator->errors()->add('due_dates.'.$dueDateIndex, self::DUE_DATE_OUTSIDE_YEAR_MESSAGE);
            }
        }
    }

    private function rejectDownPaymentMismatch(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['due_dates', 'down_payment'])) {
            return;
        }

        $hasInstallments = count($this->input('due_dates')) > 0;
        $hasDownPayment = $this->input('down_payment') !== null;

        if ($hasInstallments && ! $hasDownPayment) {
            $validator->errors()->add('down_payment', self::DOWN_PAYMENT_REQUIRED_MESSAGE);
        }

        if (! $hasInstallments && $hasDownPayment) {
            $validator->errors()->add('down_payment', self::DOWN_PAYMENT_FORBIDDEN_MESSAGE);
        }
    }

    private function rejectAdjustmentMismatch(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['due_dates', 'adjustment', 'adjustment.kind']) || $this->input('adjustment') === null) {
            return;
        }

        $hasInstallments = count($this->input('due_dates')) > 0;
        $adjustmentKind = $this->input('adjustment.kind');

        if ($adjustmentKind === PaymentPlan::KIND_DISCOUNT && $hasInstallments) {
            $validator->errors()->add('adjustment.kind', self::DISCOUNT_NOT_ALLOWED_MESSAGE);
        }

        if ($adjustmentKind === PaymentPlan::KIND_SURCHARGE && ! $hasInstallments) {
            $validator->errors()->add('adjustment.kind', self::SURCHARGE_NOT_ALLOWED_MESSAGE);
        }
    }
}
