<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ShowTrialBalanceRequest extends ShowAsOfReportRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'type' => ['nullable', Rule::in(Account::TYPES)],
            'include_zero' => ['nullable', 'boolean'],
        ];
    }
}
