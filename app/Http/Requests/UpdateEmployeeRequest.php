<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Unique;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), 'password');
    }

    protected function getUniqueEmailRule(): Unique
    {
        return parent::getUniqueEmailRule()->ignore($this->route('id'));
    }
}
