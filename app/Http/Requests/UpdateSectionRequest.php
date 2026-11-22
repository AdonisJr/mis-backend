<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\Room;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Validator;

class UpdateSectionRequest extends StoreSectionRequest
{
    public const ENROLLED_SECTION_LOCKED_MESSAGE = 'This field cannot change once the section has enrollments.';

    public const CLASSES_SECTION_LOCKED_MESSAGE = 'This field cannot change once the section has classes.';

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

    protected function isRoomChanged(Room $room): bool
    {
        return Section::find($this->route('id'))?->room_id !== $room->id;
    }

    protected function isAdviserChanged(User $adviser): bool
    {
        // an unchanged adviser is kept even if later deactivated or no longer a teacher
        return Section::find($this->route('id'))?->adviser_id !== $adviser->id;
    }

    protected function buildSameYearAdviserQuery(): Builder
    {
        return parent::buildSameYearAdviserQuery()->whereKeyNot($this->route('id'));
    }

    protected function buildSameGroupQuery(): Builder
    {
        return parent::buildSameGroupQuery()->whereKeyNot($this->route('id'));
    }

    private function rejectLockedChanges(Validator $validator): void
    {
        $lockedSection = $this->findLockedSection();

        if (! $lockedSection) {
            return;
        }

        $lockedMessage = $lockedSection->enrollments()->exists() ? self::ENROLLED_SECTION_LOCKED_MESSAGE : self::CLASSES_SECTION_LOCKED_MESSAGE;

        foreach (self::LOCKED_FIELDS as $lockedField) {
            $isLockedFieldChanged = ! $validator->errors()->has($lockedField) && $this->isFieldChanged($lockedSection, $lockedField);

            if ($isLockedFieldChanged) {
                $validator->errors()->add($lockedField, $lockedMessage);
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

    private function findLockedSection(): ?Section
    {
        // a missing id returns null here and the controller answers 404
        $section = Section::find($this->route('id'));

        return $section && ($section->enrollments()->exists() || $section->schoolClasses()->exists()) ? $section : null;
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
