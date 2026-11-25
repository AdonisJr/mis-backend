<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use Illuminate\Validation\Validator;

class ReopenClassQuarterRequest extends ShowClassQuarterRequest
{
    private const REASON_MAX_LENGTH = 500;

    public function authorize(): bool
    {
        // the registry route group already limits the caller; only the 404 lookup remains
        $this->findSchoolClass();

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectDraftQuarter($validator),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    private function rejectDraftQuarter(Validator $validator): void
    {
        if ($validator->errors()->has('quarter')) {
            return;
        }

        if (! $this->findSchoolClass()->isQuarterSubmitted($this->resolveQuarter())) {
            $validator->errors()->add('quarter', SchoolClass::QUARTER_NOT_SUBMITTED_MESSAGE);
        }
    }
}
