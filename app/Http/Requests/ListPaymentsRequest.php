<?php

namespace App\Http\Requests;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from') ?: today()->toDateString(),
            'date_to' => $this->input('date_to') ?: today()->toDateString(),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'method' => ['nullable', 'string', Rule::in(Payment::METHODS)],
            'recorded_by' => StoreSectionRequest::buildIdRules('nullable', User::class),
            'status' => ['nullable', 'string', Rule::in(Payment::STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
