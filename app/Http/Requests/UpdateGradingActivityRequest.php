<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class UpdateGradingActivityRequest extends StoreGradingActivityRequest
{
    public const BELOW_RECORDED_SCORE_MESSAGE = 'The highest possible score cannot be lower than a recorded score (%s).';

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectBelowRecordedScore($validator),
        ];
    }

    private function rejectBelowRecordedScore(Validator $validator): void
    {
        // an invalid value is never compared
        if ($validator->errors()->has('highest_possible_score')) {
            return;
        }

        $recordedScore = $this->findActivity()->findScoreAbove($this->input('highest_possible_score'));

        if ($recordedScore !== null) {
            $validator->errors()->add('highest_possible_score', sprintf(self::BELOW_RECORDED_SCORE_MESSAGE, $recordedScore));
        }
    }
}
