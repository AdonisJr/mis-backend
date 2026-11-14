<?php

namespace App\Http\Requests;

use App\Models\DiscountType;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStudentDiscountsRequest extends FormRequest
{
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
            'search' => ['nullable', 'string', 'max:255'],
            'discount_type_id' => StoreSectionRequest::buildIdRules('nullable', DiscountType::class),
            'source' => ['nullable', 'string', Rule::in(DiscountType::SOURCES)],
            'student_id' => StoreSectionRequest::buildIdRules('nullable', Student::class),
        ];
    }
}
