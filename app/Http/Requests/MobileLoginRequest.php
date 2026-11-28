<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MobileLoginRequest extends FormRequest
{
    public const USERNAME_FIELD = 'username';

    public const LRN_FIELD = 'lrn';

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
        // no digit rule on the LRN: a malformed LRN must not be distinguishable from an unknown one
        return [
            self::USERNAME_FIELD => ['required_without:'.self::LRN_FIELD, 'nullable', 'string', 'max:255'],
            self::LRN_FIELD => ['required_without:'.self::USERNAME_FIELD, 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function resolveIdentifierField(): string
    {
        return $this->filled(self::USERNAME_FIELD) ? self::USERNAME_FIELD : self::LRN_FIELD;
    }

    public function resolveIdentifier(): string
    {
        return (string) $this->validated($this->resolveIdentifierField());
    }
}
