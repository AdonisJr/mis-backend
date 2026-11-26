<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;

class ShowArAgingRequest extends ShowAsOfReportRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'school_year_id' => StoreSectionRequest::buildIdRules('nullable', SchoolYear::class),
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
