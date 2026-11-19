<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListAssessmentsRequest extends FormRequest
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
            'grade_level_id' => StoreSectionRequest::buildIdRules('nullable', GradeLevel::class),
            'payment_plan_id' => StoreSectionRequest::buildIdRules('nullable', PaymentPlan::class),
        ];
    }
}
