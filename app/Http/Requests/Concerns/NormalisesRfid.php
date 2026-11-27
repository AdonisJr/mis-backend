<?php

namespace App\Http\Requests\Concerns;

trait NormalisesRfid
{
    /**
     * @return array<int, string>
     */
    protected function buildRfidRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    protected function prepareForValidation(): void
    {
        $rfidInput = $this->input('rfid');

        if (! is_string($rfidInput)) {
            return;
        }

        // stored and broadcast upper-case, so a plain equality check and the unique index ignore case in effect
        $this->merge(['rfid' => mb_strtoupper(trim($rfidInput))]);
    }
}
