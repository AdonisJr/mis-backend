<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMobileStudent;
use App\Models\SchoolYear;
use App\Support\ReportCardBuilder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowMobileGradesRequest extends FormRequest
{
    use ResolvesMobileStudent;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => StoreSectionRequest::buildIdRules('nullable', SchoolYear::class),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnavailableYear($validator),
        ];
    }

    private function rejectUnavailableYear(Validator $validator): void
    {
        if ($validator->errors()->has('school_year_id') || ! $this->filled('school_year_id')) {
            return;
        }

        $schoolYear = SchoolYear::findOrFail($this->input('school_year_id'));

        if ($schoolYear->term_type === SchoolYear::TERM_TYPE_TRIMESTER) {
            $validator->errors()->add('school_year_id', ShowClassQuarterRequest::TRIMESTER_MESSAGE);

            return;
        }

        if (! ReportCardBuilder::findSourceEnrollment($this->findStudent(), $schoolYear->id)) {
            $validator->errors()->add('school_year_id', ShowReportCardRequest::NO_ENROLLMENT_MESSAGE);
        }
    }
}
