<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePromissoryNoteRequest extends FormRequest
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
            'promised_amount' => ['bail', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePaymentRequest::AMOUNT_MAX],
            'promise_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
