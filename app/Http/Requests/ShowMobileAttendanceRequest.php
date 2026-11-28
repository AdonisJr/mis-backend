<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowMobileAttendanceRequest extends FormRequest
{
    private ?Student $student = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['month' => $this->input('month') ?: today()->format('Y-m')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }

    // 404 when the student row was removed and user_id was nulled
    public function findStudent(): Student
    {
        return $this->student ??= $this->user()->student()->firstOrFail();
    }
}
