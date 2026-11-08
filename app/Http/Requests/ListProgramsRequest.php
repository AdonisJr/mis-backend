<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProgramsRequest extends FormRequest
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    private const SEARCH_RULES = ['nullable', 'string', 'max:255'];

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
            'search' => self::SEARCH_RULES,
            'stage' => ['nullable', 'string', Rule::in(GradeLevel::PROGRAM_STAGES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ];
    }
}
