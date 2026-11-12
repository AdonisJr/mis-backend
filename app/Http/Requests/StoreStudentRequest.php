<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreStudentRequest extends FormRequest
{
    private const REQUIRED_TEXT_RULES = ['required', 'string', 'max:255'];

    private const OPTIONAL_TEXT_RULES = ['nullable', 'string', 'max:255'];

    private const REQUIRED_CONTACT_RULES = ['required', 'string', 'max:20'];

    private const OPTIONAL_CONTACT_RULES = ['nullable', 'string', 'max:20'];

    private const LRN_DIGITS_RULE = 'digits:12';

    private const BIRTH_DATE_RULES = ['required', 'date_format:Y-m-d', 'before:today'];

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
            'lrn' => ['required', 'string', self::LRN_DIGITS_RULE, $this->getUniqueLrnRule()],
            'first_name' => self::REQUIRED_TEXT_RULES,
            'middle_name' => self::OPTIONAL_TEXT_RULES,
            'last_name' => self::REQUIRED_TEXT_RULES,
            'birth_date' => self::BIRTH_DATE_RULES,
            'gender' => ['required', Rule::in(Student::GENDERS)],
            'address' => self::OPTIONAL_TEXT_RULES,
            'contact' => self::OPTIONAL_CONTACT_RULES,
            'guardian_name' => self::REQUIRED_TEXT_RULES,
            'guardian_relationship' => self::REQUIRED_TEXT_RULES,
            'guardian_contact' => self::REQUIRED_CONTACT_RULES,
        ];
    }

    protected function getUniqueLrnRule(): Unique
    {
        return Rule::unique(Student::class, 'lrn');
    }
}
