<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateSchoolYearRequest extends StoreSchoolYearRequest
{
    protected function getUniqueNameRule(): Unique
    {
        return parent::getUniqueNameRule()->ignore($this->route('id'));
    }
}
