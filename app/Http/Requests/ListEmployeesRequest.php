<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEmployeesRequest extends FormRequest
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
            'role' => ['nullable', 'string', Rule::in(Role::STAFF_ROLES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ];
    }
}
