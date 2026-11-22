<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesClassMeetings;
use App\Models\CurriculumEntry;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class StoreSchoolClassRequest extends FormRequest
{
    use ValidatesClassMeetings;

    public const SECTION_NOT_CURRICULAR_MESSAGE = 'Classes are only for junior and senior high school sections.';

    public const ENTRY_YEAR_MESSAGE = "The curriculum entry must belong to the section's school year.";

    public const ENTRY_GRADE_LEVEL_MESSAGE = "The curriculum entry must match the section's grade level.";

    public const ENTRY_PROGRAM_MESSAGE = "The curriculum entry must match the section's program.";

    public const DUPLICATE_CLASS_MESSAGE = 'This subject already has a class in this section.';

    public const TEACHER_NOT_ACTIVE_MESSAGE = 'The teacher must be an active employee with the teacher role.';

    private ?Section $targetSection = null;

    private bool $isTargetSectionLoaded = false;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => StoreSectionRequest::buildIdRules('required', Section::class),
            'curriculum_entry_id' => StoreSectionRequest::buildIdRules('required', CurriculumEntry::class),
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
            fn (Validator $validator) => $this->rejectNonCurricularSection($validator),
            fn (Validator $validator) => $this->rejectUnfitEntry($validator),
            fn (Validator $validator) => $this->rejectDuplicateClass($validator),
            fn (Validator $validator) => $this->rejectNonTeacher($validator),
            ...$this->buildMeetingChecks(),
        ];
    }

    public function findDuplicateClass(): ?SchoolClass
    {
        return SchoolClass::where('section_id', $this->input('section_id'))->where('curriculum_entry_id', $this->input('curriculum_entry_id'))->first();
    }

    public function buildNewClass(): SchoolClass
    {
        $schoolClass = new SchoolClass($this->safe()->only(['section_id', 'curriculum_entry_id', 'teacher_id']));
        $schoolClass->forceFill([
            'school_year_id' => Section::findOrFail($this->validated('section_id'))->school_year_id,
            'term_number' => CurriculumEntry::findOrFail($this->validated('curriculum_entry_id'))->term_number,
        ]);

        return $schoolClass;
    }

    protected function isTeacherChanged(User $teacher): bool
    {
        return true;
    }

    protected function findTargetSection(): ?Section
    {
        if (! $this->isTargetSectionLoaded) {
            $sectionId = $this->input('section_id');
            $isSectionIdWellFormed = (is_int($sectionId) || is_string($sectionId)) && ctype_digit((string) $sectionId);
            $this->targetSection = $isSectionIdWellFormed ? Section::find($sectionId) : null;
            $this->isTargetSectionLoaded = true;
        }

        return $this->targetSection;
    }

    protected function findStoredRoomIds(): Collection
    {
        return collect();
    }

    protected function rejectNonTeacher(Validator $validator): void
    {
        if ($validator->errors()->has('teacher_id')) {
            return;
        }

        if (! $this->isTeacherChanged(User::findOrFail($this->input('teacher_id')))) {
            return;
        }

        if (User::activeTeachers()->whereKey($this->input('teacher_id'))->exists()) {
            return;
        }

        $validator->errors()->add('teacher_id', self::TEACHER_NOT_ACTIVE_MESSAGE);
    }

    private function rejectNonCurricularSection(Validator $validator): void
    {
        if ($validator->errors()->has('section_id')) {
            return;
        }

        $section = Section::with('gradeLevel')->findOrFail($this->input('section_id'));

        if (in_array($section->gradeLevel->stage, CurriculumEntry::CURRICULUM_STAGES, true)) {
            return;
        }

        $validator->errors()->add('section_id', self::SECTION_NOT_CURRICULAR_MESSAGE);
    }

    private function rejectUnfitEntry(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['section_id', 'curriculum_entry_id'])) {
            return;
        }

        $entryError = $this->findEntryError(Section::findOrFail($this->input('section_id')), CurriculumEntry::findOrFail($this->input('curriculum_entry_id')));

        if ($entryError === null) {
            return;
        }

        $validator->errors()->add('curriculum_entry_id', $entryError);
    }

    private function findEntryError(Section $section, CurriculumEntry $entry): ?string
    {
        return match (true) {
            $entry->school_year_id !== $section->school_year_id => self::ENTRY_YEAR_MESSAGE,
            $entry->grade_level_id !== $section->grade_level_id => self::ENTRY_GRADE_LEVEL_MESSAGE,
            $entry->program_id !== $section->program_id => self::ENTRY_PROGRAM_MESSAGE,
            default => null,
        };
    }

    private function rejectDuplicateClass(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['section_id', 'curriculum_entry_id'])) {
            return;
        }

        if (! $this->findDuplicateClass()) {
            return;
        }

        $validator->errors()->add('curriculum_entry_id', self::DUPLICATE_CLASS_MESSAGE);
    }
}
