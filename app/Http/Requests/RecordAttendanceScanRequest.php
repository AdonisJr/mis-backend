<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesRfid;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordAttendanceScanRequest extends FormRequest
{
    use NormalisesRfid;

    public const NO_ACTIVE_YEAR_MESSAGE = 'No school year is active.';

    public const NO_ACTIVE_YEAR_KEY = 'school_year';

    private ?SchoolYear $activeSchoolYear = null;

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
            'rfid' => $this->buildRfidRules(),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectMissingActiveYear($validator),
        ];
    }

    public function findActiveSchoolYear(): ?SchoolYear
    {
        return $this->activeSchoolYear ??= SchoolYear::where('is_active', true)->first();
    }

    // runs before the controller's card lookup, so a scan with no year is a 422 even for an unknown card
    private function rejectMissingActiveYear(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || $this->findActiveSchoolYear()) {
            return;
        }

        $validator->errors()->add(self::NO_ACTIVE_YEAR_KEY, self::NO_ACTIVE_YEAR_MESSAGE);
    }
}
