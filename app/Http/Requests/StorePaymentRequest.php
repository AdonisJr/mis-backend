<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    // decimal(14,2) maximum, so an overflowing amount is a 422 and not a database error
    public const AMOUNT_MAX = '999999999999.99';

    public const REFERENCE_REQUIRED_MESSAGE = 'A reference no. is required for e-wallet, bank transfer and check payments.';

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
            'assessment_id' => StoreSectionRequest::buildIdRules('required', Assessment::class),
            'amount' => ['bail', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::AMOUNT_MAX],
            'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'string', Rule::in(Payment::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:50', 'required_unless:method,'.Payment::METHOD_CASH],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reference_no.required_unless' => self::REFERENCE_REQUIRED_MESSAGE];
    }
}
