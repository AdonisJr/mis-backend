<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListSectionsRequest extends FormRequest
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
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'program_id' => StoreSectionRequest::buildIdRules('nullable', Program::class),
            'search' => self::SEARCH_RULES,
        ];
    }
}
