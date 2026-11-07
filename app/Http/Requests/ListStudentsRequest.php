<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListStudentsRequest extends FormRequest
{
    private const FILTER_RULES = ['nullable', 'string', 'max:255'];

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
            'search' => self::FILTER_RULES,
            'grade_level' => self::FILTER_RULES,
            'section' => self::FILTER_RULES,
        ];
    }
}
