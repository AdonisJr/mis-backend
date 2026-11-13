<?php

namespace App\Http\Requests;

use App\Models\FeeItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFeeItemRequest extends FormRequest
{
    public const NAME_TAKEN_MESSAGE = 'The name has already been taken.';

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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(FeeItem::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:1000'],
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

    protected function buildSameNameQuery(): Builder
    {
        // LOWER on both sides folds stored and submitted names the same way on every database
        return FeeItem::query()->whereRaw('LOWER(name) = LOWER(?)', [$this->input('name')]);
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
