<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateStudentRequest extends StoreStudentRequest
{
    protected function getUniqueLrnRule(): Unique
    {
        return parent::getUniqueLrnRule()->ignore($this->route('id'));
    }
}
