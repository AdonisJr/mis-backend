<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RefusesWithMessage;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Support\ClassListAccess;
use App\Support\ReportCardBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowReportCardRequest extends FormRequest
{
    use RefusesWithMessage;

    public const NOT_PERMITTED_MESSAGE = 'You can only view report cards of students in the section you advise.';

    public const NO_ENROLLMENT_MESSAGE = 'The student has no enrollment in this school year.';

    private ?Student $student = null;

    // a missing or non-numeric year casts to 0, so a teacher is refused before the year's 422 and the registry passes on to it
    public function authorize(): bool
    {
        $schoolYearId = is_scalar($this->input('school_year_id')) ? (int) $this->input('school_year_id') : 0;

        return (new ClassListAccess($this->user()))->canReadReportCard($this->findStudent(), $schoolYearId);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnavailableCard($validator),
        ];
    }

    public function findStudent(): Student
    {
        return $this->student ??= Student::findOrFail($this->route('id'));
    }

    public function findSchoolYear(): SchoolYear
    {
        return SchoolYear::findOrFail($this->validated('school_year_id'));
    }

    protected function resolveNotPermittedMessage(): string
    {
        return self::NOT_PERMITTED_MESSAGE;
    }

    private function rejectUnavailableCard(Validator $validator): void
    {
        if ($validator->errors()->has('school_year_id')) {
            return;
        }

        $schoolYear = SchoolYear::findOrFail($this->input('school_year_id'));

        if ($schoolYear->term_type === SchoolYear::TERM_TYPE_TRIMESTER) {
            $validator->errors()->add('school_year_id', ShowClassQuarterRequest::TRIMESTER_MESSAGE);

            return;
        }

        if (! ReportCardBuilder::findSourceEnrollment($this->findStudent(), $schoolYear->id)) {
            $validator->errors()->add('student_id', self::NO_ENROLLMENT_MESSAGE);
        }
    }
}
