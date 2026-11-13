<?php

namespace App\Http\Requests;

use App\Models\FeeSchedule;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Validator;

class UpdateFeeScheduleRequest extends StoreFeeScheduleRequest
{
    public const KEY_LOCKED_MESSAGE = 'This field cannot change once the fee schedule exists.';

    private ?FeeSchedule $storedSchedule = null;

    private bool $isStoredScheduleLoaded = false;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::buildLineRules();
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectLockedKeys($validator),
            fn (Validator $validator) => $this->rejectInactiveFeeItems($validator),
        ];
    }

    protected function isLineUnchanged(array $line): bool
    {
        $storedLine = $this->findStoredSchedule()?->lines->firstWhere('fee_item_id', (int) $line['fee_item_id']);

        // an inactive item may stay only when the stored line already holds it with the same amount
        return $storedLine !== null && BigDecimal::of($line['amount'])->isEqualTo($storedLine->amount);
    }

    private function rejectLockedKeys(Validator $validator): void
    {
        $storedSchedule = $this->findStoredSchedule();

        if (! $storedSchedule) {
            return;
        }

        foreach (FeeSchedule::KEY_FIELDS as $keyField) {
            if ($this->isKeyChanged($storedSchedule, $keyField)) {
                $validator->errors()->add($keyField, self::KEY_LOCKED_MESSAGE);
            }
        }
    }

    private function findStoredSchedule(): ?FeeSchedule
    {
        if (! $this->isStoredScheduleLoaded) {
            // a missing id leaves this null and the controller answers 404
            $this->storedSchedule = FeeSchedule::with('lines')->find($this->route('id'));
            $this->isStoredScheduleLoaded = true;
        }

        return $this->storedSchedule;
    }

    private function isKeyChanged(FeeSchedule $storedSchedule, string $keyField): bool
    {
        if (! $this->has($keyField)) {
            return false;
        }

        $submittedValue = $this->input($keyField);
        $normalizedValue = $submittedValue === null || $submittedValue === '' ? null : (int) $submittedValue;

        return $normalizedValue !== $storedSchedule->{$keyField};
    }
}
