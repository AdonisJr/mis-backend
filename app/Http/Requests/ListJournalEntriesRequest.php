<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListJournalEntriesRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(JournalEntry::DISPLAY_STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'account_id' => StoreSectionRequest::buildIdRules('nullable', Account::class),
            'source' => ['nullable', 'string', Rule::in(JournalEntry::SOURCES)],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
