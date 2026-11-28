<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesMobileNumber;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumberNormaliser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkParentRequest extends FormRequest
{
    use NormalisesMobileNumber;

    public const NO_PARENT_MESSAGE = 'No parent account has this mobile number.';

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
            'mobile_number' => ['bail', 'required', 'string', MobileNumberNormaliser::FORMAT_RULE, Rule::exists(User::class, 'mobile_number')],
            'relationship' => ['required', 'string', Rule::in(Student::PARENT_RELATIONSHIPS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile_number.regex' => MobileNumberNormaliser::FORMAT_MESSAGE,
            'mobile_number.exists' => self::NO_PARENT_MESSAGE,
        ];
    }
}
