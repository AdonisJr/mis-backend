<?php

namespace App\Http\Requests;

use App\Models\JournalEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReverseJournalEntryRequest extends FormRequest
{
    public const DATE_BEFORE_ORIGINAL_MESSAGE = 'The reversal date cannot be before the original entry date.';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->input('date') ?: today()->toDateString()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectDateBeforeOriginal($validator)];
    }

    private function rejectDateBeforeOriginal(Validator $validator): void
    {
        // a missing entry skips the check and the controller answers 404
        $originalEntry = JournalEntry::find($this->route('id'));

        if ($validator->errors()->has('date') || $originalEntry === null) {
            return;
        }

        if ($this->input('date') >= $originalEntry->entry_date->toDateString()) {
            return;
        }

        $validator->errors()->add('date', self::DATE_BEFORE_ORIGINAL_MESSAGE);
    }
}
