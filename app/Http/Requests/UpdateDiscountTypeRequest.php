<?php

namespace App\Http\Requests;

use App\Models\DiscountType;
use Illuminate\Validation\Validator;

class UpdateDiscountTypeRequest extends StoreDiscountTypeRequest
{
    public const SOURCE_LOCKED_MESSAGE = 'The source cannot change while students hold this discount type.';

    public const STAGE_REMOVAL_MESSAGE = 'A stage cannot be removed while students hold this discount type.';

    private ?DiscountType $storedType = null;

    private bool $isStoredTypeLoaded = false;

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectLockedChangesWhileHeld($validator),
        ];
    }

    public function buildNameCandidate(): DiscountType
    {
        $storedType = $this->findStoredType();
        $nameCandidate = parent::buildNameCandidate();

        // a missing type has no id to exclude; the controller answers 404 for it
        return $storedType ? $nameCandidate->forceFill(['id' => $storedType->id]) : $nameCandidate;
    }

    private function findStoredType(): ?DiscountType
    {
        if (! $this->isStoredTypeLoaded) {
            $this->storedType = DiscountType::find($this->route('id'));
            $this->isStoredTypeLoaded = true;
        }

        return $this->storedType;
    }

    private function rejectLockedChangesWhileHeld(Validator $validator): void
    {
        $storedType = $this->findStoredType();

        if (! $storedType || ! $storedType->studentDiscounts()->exists()) {
            return;
        }

        $this->rejectChangedSource($validator, $storedType);
        $this->rejectRemovedStages($validator, $storedType);
    }

    private function rejectChangedSource(Validator $validator, DiscountType $storedType): void
    {
        if ($validator->errors()->has('source') || $this->input('source') === $storedType->source) {
            return;
        }

        $validator->errors()->add('source', self::SOURCE_LOCKED_MESSAGE);
    }

    private function rejectRemovedStages(Validator $validator, DiscountType $storedType): void
    {
        $hasStageError = collect($validator->errors()->keys())->contains(fn (string $errorKey) => str_starts_with($errorKey, 'stages'));

        if ($hasStageError || array_diff($storedType->stages, $this->input('stages')) === []) {
            return;
        }

        $validator->errors()->add('stages', self::STAGE_REMOVAL_MESSAGE);
    }
}
