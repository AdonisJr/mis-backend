<?php

namespace App\Http\Requests;

use App\Models\DiscountType;
use App\Models\GradeLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDiscountTypeRequest extends FormRequest
{
    public const NAME_TAKEN_MESSAGE = 'The name has already been taken.';

    public const COLLEGE_STAGE_MESSAGE = 'Discount types are only for junior and senior high school.';

    private const PERCENT_MAX = '100';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Build the value rules by reading the submitted value type.
     *
     * @return list<string>
     */
    protected function buildValueRules(): array
    {
        $valueRules = ['bail', 'required', 'numeric', 'decimal:0,2', 'gt:0'];
        $submittedType = $this->input('value_type');

        if ($submittedType === DiscountType::VALUE_TYPE_FIXED) {
            $valueRules[] = 'max:'.StoreFeeScheduleRequest::AMOUNT_MAX;
        }

        if ($submittedType === DiscountType::VALUE_TYPE_PERCENT) {
            $valueRules[] = 'max:'.self::PERCENT_MAX;
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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source' => ['bail', 'required', 'string', Rule::in(DiscountType::SOURCES)],
            'value_type' => ['bail', 'required', 'string', Rule::in(DiscountType::VALUE_TYPES)],
            'value' => $this->buildValueRules(),
            'base' => ['bail', 'required', 'string', Rule::in(DiscountType::BASES)],
            'stages' => ['required', 'array', 'list'],
            'stages.*' => ['bail', 'required', 'string', Rule::notIn([GradeLevel::STAGE_COLLEGE]), Rule::in(DiscountType::STAGES), 'distinct'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stages.*.not_in' => self::COLLEGE_STAGE_MESSAGE,
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTakenName($validator),
        ];
    }

    public function buildNameCandidate(): DiscountType
    {
        return new DiscountType(['name' => $this->input('name')]);
    }

    /** @return array<string, mixed> */
    public function buildTypeAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'source' => $validated['source'],
            'value_type' => $validated['value_type'],
            'value' => $validated['value'],
            'base' => $validated['base'],
            // canonical order, so [shs, jhs] and [jhs, shs] store the same list
            'stages' => array_values(array_intersect(DiscountType::STAGES, $validated['stages'])),
        ];
    }

    private function rejectTakenName(Validator $validator): void
    {
        if ($validator->errors()->has('name')) {
            return;
        }

        if (! DiscountType::withSameName($this->buildNameCandidate())->exists()) {
            return;
        }

        $validator->errors()->add('name', self::NAME_TAKEN_MESSAGE);
    }
}
