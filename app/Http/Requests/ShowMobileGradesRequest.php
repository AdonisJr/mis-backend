<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Support\ReportCardBuilder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowMobileGradesRequest extends FormRequest
{
    private ?Student $student = null;

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

    // 404 when the student row was removed and user_id was nulled
    public function findStudent(): Student
    {
        return $this->student ??= $this->user()->student()->firstOrFail();
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
