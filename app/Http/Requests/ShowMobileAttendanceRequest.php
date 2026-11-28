<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMobileStudent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowMobileAttendanceRequest extends FormRequest
{
    use ResolvesMobileStudent;

    protected function prepareForValidation(): void
    {
        $this->merge(['month' => $this->input('month') ?: today()->format('Y-m')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}
