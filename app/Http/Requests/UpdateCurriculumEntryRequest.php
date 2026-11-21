<?php

namespace App\Http\Requests;

use App\Models\CurriculumEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurriculumEntryRequest extends FormRequest
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
            'sort_order' => ['bail', 'present', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'min:1', 'max:'.CurriculumEntry::SORT_ORDER_MAX],
        ];
    }
}
