<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckPaymentHoldRequest extends FormRequest
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
            'student_id' => StoreSectionRequest::buildIdRules('required', Student::class),
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
        ];
    }
}
