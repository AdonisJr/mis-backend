<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListTimetableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'term_number' => ['bail', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTermOutOfRange($validator),
        ];
    }

    private function rejectTermOutOfRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['school_year_id', 'term_number']) || ! $this->filled('term_number')) {
            return;
        }

        $termCount = SchoolYear::TERM_COUNTS[SchoolYear::findOrFail($this->input('school_year_id'))->term_type];
        $term = (int) $this->input('term_number');

        if ($term >= 1 && $term <= $termCount) {
            return;
        }

        $validator->errors()->add('term_number', sprintf(StoreCurriculumEntryRequest::TERM_RANGE_MESSAGE, $termCount));
    }
}
