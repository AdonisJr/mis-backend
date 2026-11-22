<?php

namespace App\Http\Requests;

use App\Models\GradingActivity;
use App\Models\SchoolClass;
use App\Support\ClassListAccess;
use Illuminate\Validation\Validator;

class ChangeClassQuarterRequest extends ShowClassQuarterRequest
{
    public const WRITE_NOT_PERMITTED_MESSAGE = 'Only the current teacher of this class can change its class record.';

    private bool $isActivityLoaded = false;

    private ?GradingActivity $activity = null;

    public function authorize(): bool
    {
        if (! (new ClassListAccess($this->user()))->canWriteClass($this->findSchoolClass())) {
            return false;
        }

        // a foreign activity is a 404 only after the scope check
        $this->findActivity();

        return true;
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectSubmittedQuarter($validator),
        ];
    }

    public function findActivity(): ?GradingActivity
    {
        if ($this->isActivityLoaded) {
            return $this->activity;
        }

        $this->isActivityLoaded = true;
        $activityId = $this->route('activityId');
        $this->activity = $activityId === null ? null : $this->findSchoolClass()->gradingActivities()->where('quarter', $this->resolveQuarter())->findOrFail($activityId);

        return $this->activity;
    }

    protected function resolveNotPermittedMessage(): string
    {
        return self::WRITE_NOT_PERMITTED_MESSAGE;
    }

    private function rejectSubmittedQuarter(Validator $validator): void
    {
        if ($validator->errors()->has('quarter')) {
            return;
        }

        if ($this->findSchoolClass()->isQuarterSubmitted($this->resolveQuarter())) {
            $validator->errors()->add('quarter', SchoolClass::QUARTER_SUBMITTED_MESSAGE);
        }
    }
}
