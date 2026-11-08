<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateProgramRequest extends StoreProgramRequest
{
    protected function getUniqueCodeRule(): Unique
    {
        return parent::getUniqueCodeRule()->ignore($this->route('id'));
    }
}
