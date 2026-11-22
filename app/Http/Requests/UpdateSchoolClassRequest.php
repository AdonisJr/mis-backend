<?php

namespace App\Http\Requests;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class UpdateSchoolClassRequest extends StoreSchoolClassRequest
{
    public const KEY_LOCKED_MESSAGE = 'This field cannot change once the class exists.';

    private const KEY_FIELDS = ['section_id', 'curriculum_entry_id'];

    private ?SchoolClass $storedClass = null;

    private bool $isStoredClassLoaded = false;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'teacher_id' => StoreSectionRequest::buildIdRules('required', User::class),
            ...$this->buildMeetingRules(),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectLockedKeys($validator),
            fn (Validator $validator) => $this->rejectNonTeacher($validator),
            ...$this->buildMeetingChecks(),
        ];
    }

    public function findDuplicateClass(): ?SchoolClass
    {
        return null;
    }

    protected function isTeacherChanged(User $teacher): bool
    {
        // an unchanged teacher is kept even if later deactivated or no longer a teacher
        return $this->findStoredClass()?->teacher_id !== $teacher->id;
    }

    protected function findTargetSection(): ?Section
    {
        return $this->findStoredClass()?->section;
    }

    protected function findStoredRoomIds(): Collection
    {
        return $this->findStoredClass()?->meetings->pluck('room_id')->filter() ?? collect();
    }

    private function rejectLockedKeys(Validator $validator): void
    {
        $storedClass = $this->findStoredClass();

        if (! $storedClass) {
            return;
        }

        foreach (self::KEY_FIELDS as $keyField) {
            if ($this->isKeyChanged($storedClass, $keyField)) {
                $validator->errors()->add($keyField, self::KEY_LOCKED_MESSAGE);
            }
        }
    }

    private function findStoredClass(): ?SchoolClass
    {
        if (! $this->isStoredClassLoaded) {
            // a missing id leaves this null and the controller answers 404
            $this->storedClass = SchoolClass::with('meetings')->find($this->route('id'));
            $this->isStoredClassLoaded = true;
        }

        return $this->storedClass;
    }

    private function isKeyChanged(SchoolClass $storedClass, string $keyField): bool
    {
        if (! $this->has($keyField)) {
            return false;
        }

        $submittedValue = $this->input($keyField);
        $normalizedValue = $submittedValue === null || $submittedValue === '' ? null : (int) $submittedValue;

        return $normalizedValue !== $storedClass->{$keyField};
    }
}
