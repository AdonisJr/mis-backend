<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreEmployeeRequest extends FormRequest
{
    public const PASSWORD_RULES = ['required', 'string', 'min:8', 'max:255', 'confirmed'];

    private const NAME_RULES = ['required', 'string', 'max:255'];

    private const CONTACT_RULES = ['nullable', 'string', 'max:20'];

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
            'name' => self::NAME_RULES,
            'email' => ['required', 'string', 'email', 'max:255', $this->getUniqueEmailRule()],
            'contact' => self::CONTACT_RULES,
            'password' => self::PASSWORD_RULES,
            'roles' => ['required', 'list', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(Role::STAFF_ROLES), Rule::exists(Role::class, 'name')],
        ];
    }

    protected function getUniqueEmailRule(): Unique
    {
        return Rule::unique(User::class, 'email');
    }
}
