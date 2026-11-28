<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\StudentAttendance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTodayAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->input('date') ?: today()->toDateString()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'section_id' => StoreSectionRequest::buildIdRules('nullable', Section::class),
            'status' => ['nullable', 'string', Rule::in(StudentAttendance::TODAY_STATUS_FILTERS)],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
