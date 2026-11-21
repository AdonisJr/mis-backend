<?php

namespace App\Http\Requests;

use App\Models\CurriculumEntry;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCurriculumEntryRequest extends FormRequest
{
    public const GRADE_LEVEL_NOT_CURRICULAR_MESSAGE = 'Curriculum entries are only for junior and senior high school grade levels.';

    public const SUBJECT_INACTIVE_MESSAGE = 'The selected subject is inactive.';

    public const SUBJECT_TYPE_MESSAGE = 'The subject type does not match the grade level stage.';

    public const PROGRAM_NOT_ALLOWED_MESSAGE = 'A junior high school curriculum entry cannot have a program.';

    public const TERM_NOT_ALLOWED_MESSAGE = 'A junior high school curriculum entry cannot have a term.';

    public const TERM_REQUIRED_MESSAGE = 'The term number field is required for this grade level.';

    public const TERM_RANGE_MESSAGE = 'The term number must be between 1 and %d for this school year.';

    public const KEY_TAKEN_MESSAGE = 'This subject is already in the curriculum for this school year, grade level, program and term.';

    private ?GradeLevel $gradeLevel = null;

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
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'grade_level_id' => StoreSectionRequest::buildIdRules('required', GradeLevel::class),
            'subject_id' => StoreSectionRequest::buildIdRules('required', Subject::class),
            'program_id' => StoreSectionRequest::buildIdRules('nullable', Program::class),
            'term_number' => ['bail', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE],
            'sort_order' => ['bail', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'min:1', 'max:'.CurriculumEntry::SORT_ORDER_MAX],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectNonCurricularGradeLevel($validator),
            fn (Validator $validator) => $this->rejectInactiveSubject($validator),
            fn (Validator $validator) => $this->rejectMismatchedSubjectType($validator),
            fn (Validator $validator) => $this->rejectUnfitProgram($validator),
            fn (Validator $validator) => $this->rejectUnfitTerm($validator),
            fn (Validator $validator) => $this->rejectTakenKey($validator),
        ];
    }

    public function buildKeyCandidate(): CurriculumEntry
    {
        return new CurriculumEntry($this->only(CurriculumEntry::KEY_FIELDS));
    }

    private function resolveGradeLevel(): GradeLevel
    {
        return $this->gradeLevel ??= GradeLevel::findOrFail($this->input('grade_level_id'));
    }

    private function rejectNonCurricularGradeLevel(Validator $validator): void
    {
        if ($validator->errors()->has('grade_level_id')) {
            return;
        }

        if (in_array($this->resolveGradeLevel()->stage, CurriculumEntry::CURRICULUM_STAGES, true)) {
            return;
        }

        $validator->errors()->add('grade_level_id', self::GRADE_LEVEL_NOT_CURRICULAR_MESSAGE);
    }

    private function rejectInactiveSubject(Validator $validator): void
    {
        if ($validator->errors()->has('subject_id')) {
            return;
        }

        if (Subject::findOrFail($this->input('subject_id'))->is_active) {
            return;
        }

        $validator->errors()->add('subject_id', self::SUBJECT_INACTIVE_MESSAGE);
    }

    private function rejectMismatchedSubjectType(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['grade_level_id', 'subject_id'])) {
            return;
        }

        if (Subject::findOrFail($this->input('subject_id'))->getStage() === $this->resolveGradeLevel()->stage) {
            return;
        }

        $validator->errors()->add('subject_id', self::SUBJECT_TYPE_MESSAGE);
    }

    private function rejectUnfitProgram(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['grade_level_id', 'program_id'])) {
            return;
        }

        $programError = $this->findProgramError($this->resolveGradeLevel(), $this->findSubmittedProgram());

        if ($programError === null) {
            return;
        }

        $validator->errors()->add('program_id', $programError);
    }

    private function findSubmittedProgram(): ?Program
    {
        return $this->filled('program_id') ? Program::findOrFail($this->input('program_id')) : null;
    }

    private function findProgramError(GradeLevel $gradeLevel, ?Program $program): ?string
    {
        $isJuniorHigh = $gradeLevel->stage === GradeLevel::STAGE_JHS;

        return match (true) {
            $isJuniorHigh && $program !== null => self::PROGRAM_NOT_ALLOWED_MESSAGE,
            $isJuniorHigh => null,
            $program === null => StoreSectionRequest::PROGRAM_REQUIRED_MESSAGE,
            $program->stage !== $gradeLevel->stage => StoreSectionRequest::PROGRAM_STAGE_MESSAGE,
            ! $program->is_active => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE,
            default => null,
        };
    }

    private function rejectUnfitTerm(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['school_year_id', 'grade_level_id', 'term_number'])) {
            return;
        }

        $termError = $this->findTermError($this->resolveGradeLevel(), SchoolYear::findOrFail($this->input('school_year_id')));

        if ($termError === null) {
            return;
        }

        $validator->errors()->add('term_number', $termError);
    }

    private function findTermError(GradeLevel $gradeLevel, SchoolYear $schoolYear): ?string
    {
        $isJuniorHigh = $gradeLevel->stage === GradeLevel::STAGE_JHS;
        $hasTerm = $this->filled('term_number');
        $termCount = SchoolYear::TERM_COUNTS[$schoolYear->term_type];
        $isTermOutOfRange = (int) $this->input('term_number') < 1 || (int) $this->input('term_number') > $termCount;

        return match (true) {
            $isJuniorHigh && $hasTerm => self::TERM_NOT_ALLOWED_MESSAGE,
            $isJuniorHigh => null,
            ! $hasTerm => self::TERM_REQUIRED_MESSAGE,
            $isTermOutOfRange => sprintf(self::TERM_RANGE_MESSAGE, $termCount),
            default => null,
        };
    }

    private function rejectTakenKey(Validator $validator): void
    {
        if ($validator->errors()->hasAny(CurriculumEntry::KEY_FIELDS)) {
            return;
        }

        if (! CurriculumEntry::withSameKey($this->buildKeyCandidate())->exists()) {
            return;
        }

        $validator->errors()->add('subject_id', self::KEY_TAKEN_MESSAGE);
    }
}
