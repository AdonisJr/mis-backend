<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Validator;

class UpdateSectionRequest extends StoreSectionRequest
{
    public const ENROLLED_SECTION_LOCKED_MESSAGE = 'This field cannot change once the section has enrollments.';

    public const CAPACITY_BELOW_ENROLLED_MESSAGE = 'The capacity cannot be lower than the number of enrolled students.';

    private const LOCKED_FIELDS = ['school_year_id', 'grade_level_id', 'program_id'];

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectLockedChanges($validator),
            fn (Validator $validator) => $this->rejectCapacityBelowEnrolled($validator),
        ];
    }

    protected function isProgramChanged(Program $program): bool
    {
        // a section may keep the inactive program it already has; a missing id counts as changed and the controller answers 404
        return Section::find($this->route('id'))?->program_id !== $program->id;
    }

    protected function buildSameGroupQuery(): Builder
    {
        return parent::buildSameGroupQuery()->whereKeyNot($this->route('id'));
    }

    private function rejectLockedChanges(Validator $validator): void
    {
        $enrolledSection = $this->findSectionWithEnrollments();

        foreach (self::LOCKED_FIELDS as $lockedField) {
            $isLockedFieldChanged = $enrolledSection && ! $validator->errors()->has($lockedField) && $this->isFieldChanged($enrolledSection, $lockedField);

            if ($isLockedFieldChanged) {
                $validator->errors()->add($lockedField, self::ENROLLED_SECTION_LOCKED_MESSAGE);
            }
        }
    }

    private function rejectCapacityBelowEnrolled(Validator $validator): void
    {
        $enrolledSection = $this->findSectionWithEnrollments();

        if (! $enrolledSection || $validator->errors()->has('capacity')) {
            return;
        }

        if ((int) $this->input('capacity') >= $enrolledSection->countMostSeatsTaken()) {
            return;
        }

        $validator->errors()->add('capacity', self::CAPACITY_BELOW_ENROLLED_MESSAGE);
    }

    private function findSectionWithEnrollments(): ?Section
    {
        // a missing id returns null here and the controller answers 404
        $section = Section::find($this->route('id'));

        return $section?->enrollments()->exists() ? $section : null;
    }

    private function isFieldChanged(Section $section, string $field): bool
    {
        if (! $this->has($field)) {
            return false;
        }

        $submittedValue = $this->input($field);
        $normalizedValue = $submittedValue === null || $submittedValue === '' ? null : (int) $submittedValue;

        return $normalizedValue !== $section->{$field};
    }
}
