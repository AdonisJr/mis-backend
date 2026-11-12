<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEnrollmentsRequest extends FormRequest
{
    private const SEARCH_RULES = ['nullable', 'string', 'max:255'];

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
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'term_id' => StoreSectionRequest::buildIdRules('nullable', Term::class),
            'section_id' => StoreSectionRequest::buildIdRules('nullable', Section::class),
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'status' => ['nullable', Rule::in(Enrollment::STATUSES)],
            'search' => self::SEARCH_RULES,
        ];
    }
}
