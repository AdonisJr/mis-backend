<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreSchoolYearRequest extends FormRequest
{
    private const DATE_RULE = 'date_format:Y-m-d';

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
            'name' => ['required', 'string', 'max:255', $this->getUniqueNameRule()],
            'start_date' => ['required', self::DATE_RULE],
            'end_date' => ['required', self::DATE_RULE, 'after:start_date'],
            'term_type' => ['required', 'string', Rule::in(SchoolYear::TERM_TYPES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function getUniqueNameRule(): Unique
    {
        return Rule::unique(SchoolYear::class, 'name');
    }
}
