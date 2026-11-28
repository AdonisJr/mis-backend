<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesMobileNumber;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumberNormaliser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParentAccountRequest extends FormRequest
{
    use NormalisesMobileNumber;

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
            'name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['bail', 'required', 'string', MobileNumberNormaliser::FORMAT_RULE, Rule::unique(User::class, 'mobile_number')],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'student_id' => ['required_with:relationship', ...StoreSectionRequest::buildIdRules('nullable', Student::class)],
            'relationship' => ['required_with:student_id', 'nullable', 'string', Rule::in(Student::PARENT_RELATIONSHIPS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['mobile_number.regex' => MobileNumberNormaliser::FORMAT_MESSAGE];
    }
}
