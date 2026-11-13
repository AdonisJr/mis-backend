<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CopyFeeSchedulesRequest extends FormRequest
{
    public const SAME_YEAR_MESSAGE = 'The target school year must be different from the source school year.';

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'target_school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectSameYear($validator),
        ];
    }

    private function rejectSameYear(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['source_school_year_id', 'target_school_year_id'])) {
            return;
        }

        // the cast matters: 5 and "5" are the same year but differ for a strict comparison
        if ((int) $this->input('source_school_year_id') !== (int) $this->input('target_school_year_id')) {
            return;
        }

        $validator->errors()->add('target_school_year_id', self::SAME_YEAR_MESSAGE);
    }
}
