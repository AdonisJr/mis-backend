<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAttendanceRecordsRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 31;

    public const MAX_PER_PAGE = 100;

    public const RANGE_TOO_LONG_MESSAGE = 'The date range cannot be longer than 31 days.';

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
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'student_id' => StoreSectionRequest::buildIdRules('nullable', Student::class),
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'section_id' => StoreSectionRequest::buildIdRules('nullable', Section::class),
            'status' => ['nullable', 'string', Rule::in(StudentAttendance::STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectLongRange($validator),
        ];
    }

    private function rejectLongRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
            return;
        }

        $rangeDays = (int) CarbonImmutable::parse($this->input('date_from'))->diffInDays(CarbonImmutable::parse($this->input('date_to'))) + 1;

        if ($rangeDays > self::MAX_RANGE_DAYS) {
            $validator->errors()->add('date_to', self::RANGE_TOO_LONG_MESSAGE);
        }
    }
}
