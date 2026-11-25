<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    public const NAME_TAKEN_MESSAGE = 'The name has already been taken.';

    // \z, not $, so a trailing newline is refused
    private const CODE_DIGITS_RULE = 'regex:/\A[0-9]+\z/';

    private const CODE_MIN_LENGTH = 4;

    private const CODE_MAX_LENGTH = 10;

    private const NAME_MAX_LENGTH = 150;

    private const DESCRIPTION_RULES = ['nullable', 'string', 'max:1000'];

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
            'code' => ['bail', 'required', 'string', self::CODE_DIGITS_RULE, 'min:'.self::CODE_MIN_LENGTH, 'max:'.self::CODE_MAX_LENGTH, $this->getUniqueCodeRule()],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'type' => ['required', 'string', Rule::in(Account::TYPES)],
            'subtype' => ['required', 'string', Rule::in($this->getSubtypesForType())],
            'is_contra' => ['required', 'boolean'],
            'description' => self::DESCRIPTION_RULES,
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTakenName($validator),
        ];
    }

    protected function getUniqueCodeRule(): Unique
    {
        return Rule::unique(Account::class, 'code');
    }

    protected function buildSameNameQuery(): Builder
    {
        // LOWER on both sides folds stored and submitted names the same way on every database
        return Account::query()->whereRaw('LOWER(name) = LOWER(?)', [$this->input('name')]);
    }

    private function getSubtypesForType(): array
    {
        $accountType = $this->input('type');

        if (! is_string($accountType)) {
            return [];
        }

        return Account::SUBTYPES_BY_TYPE[$accountType] ?? [];
    }

    private function rejectTakenName(Validator $validator): void
    {
        if ($validator->errors()->has('name')) {
            return;
        }

        if (! $this->buildSameNameQuery()->exists()) {
            return;
        }

        $validator->errors()->add('name', self::NAME_TAKEN_MESSAGE);
    }
}
