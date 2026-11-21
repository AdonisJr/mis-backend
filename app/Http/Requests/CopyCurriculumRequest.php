<?php

namespace App\Http\Requests;

use App\Models\CurriculumEntry;
use Illuminate\Validation\Validator;

class CopyCurriculumRequest extends CopyFeeSchedulesRequest
{
    public const TARGET_NOT_EMPTY_MESSAGE = 'The target school year already has curriculum entries.';

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectFilledTarget($validator),
        ];
    }

    private function rejectFilledTarget(Validator $validator): void
    {
        if ($validator->errors()->has('target_school_year_id')) {
            return;
        }

        if (! CurriculumEntry::where('school_year_id', $this->input('target_school_year_id'))->exists()) {
            return;
        }

        $validator->errors()->add('target_school_year_id', self::TARGET_NOT_EMPTY_MESSAGE);
    }
}
