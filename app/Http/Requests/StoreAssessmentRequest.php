<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use Illuminate\Validation\Validator;

class StoreAssessmentRequest extends PreviewAssessmentRequest
{
    public const ALREADY_ASSESSED_MESSAGE = 'The student already has a current assessment for this school year.';

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectCurrentAssessment($validator),
        ];
    }

    private function rejectCurrentAssessment(Validator $validator): void
    {
        if ($validator->errors()->hasAny(self::ID_KEYS)) {
            return;
        }

        $hasCurrentAssessment = Assessment::current()->sameStudentYear($this->buildAssessmentCandidate())->exists();

        if ($hasCurrentAssessment) {
            $validator->errors()->add('student_id', self::ALREADY_ASSESSED_MESSAGE);
        }
    }
}
