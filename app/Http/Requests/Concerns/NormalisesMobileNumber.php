<?php

namespace App\Http\Requests\Concerns;

use App\Support\MobileNumberNormaliser;

trait NormalisesMobileNumber
{
    protected function prepareForValidation(): void
    {
        $mobileNumberInput = $this->input('mobile_number');

        if (! is_string($mobileNumberInput)) {
            return;
        }

        $this->merge(['mobile_number' => MobileNumberNormaliser::normalise($mobileNumberInput)]);
    }
}
