<?php

namespace App\Http\Requests;

use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class UpdatePaymentPlanRequest extends StorePaymentPlanRequest
{
    public const SCHOOL_YEAR_LOCKED_MESSAGE = 'The school year cannot change once the payment plan exists.';

    private ?PaymentPlan $storedPlan = null;

    private bool $isStoredPlanLoaded = false;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), 'school_year_id');
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectChangedSchoolYear($validator),
            ...parent::after(),
        ];
    }

    public function buildNameCandidate(): PaymentPlan
    {
        $storedPlan = $this->findStoredPlan();

        // the stored id is excluded by the name scope, so only another plan's name collides
        return (new PaymentPlan(['school_year_id' => $storedPlan->school_year_id, 'name' => $this->input('name')]))->forceFill(['id' => $storedPlan->id]);
    }

    protected function findPlanSchoolYear(): ?SchoolYear
    {
        return $this->findStoredPlan()?->schoolYear;
    }

    private function findStoredPlan(): ?PaymentPlan
    {
        if (! $this->isStoredPlanLoaded) {
            // a missing id leaves this null and the controller answers 404
            $this->storedPlan = PaymentPlan::with('schoolYear')->find($this->route('id'));
            $this->isStoredPlanLoaded = true;
        }

        return $this->storedPlan;
    }

    private function rejectChangedSchoolYear(Validator $validator): void
    {
        $storedPlan = $this->findStoredPlan();

        if (! $storedPlan || ! $this->has('school_year_id')) {
            return;
        }

        $submittedValue = $this->input('school_year_id');
        $normalizedValue = $submittedValue === null || $submittedValue === '' ? null : (int) $submittedValue;

        if ($normalizedValue !== $storedPlan->school_year_id) {
            $validator->errors()->add('school_year_id', self::SCHOOL_YEAR_LOCKED_MESSAGE);
        }
    }
}
