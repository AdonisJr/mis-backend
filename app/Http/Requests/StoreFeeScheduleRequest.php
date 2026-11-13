<?php

namespace App\Http\Requests;

use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFeeScheduleRequest extends FormRequest
{
    // the largest value the decimal(12, 2) amount column holds, so an overflow is a 422 and not a 500
    public const AMOUNT_MAX = '9999999999.99';

    public const GRADE_LEVEL_NOT_BILLABLE_MESSAGE = 'Fee schedules are only for junior and senior high school grade levels.';

    public const PROGRAM_NOT_ALLOWED_MESSAGE = 'A junior high school fee schedule cannot have a program.';

    public const KEY_TAKEN_MESSAGE = 'A fee schedule already exists for this school year, grade level and program.';

    public const FEE_ITEM_INACTIVE_MESSAGE = 'The selected fee item is inactive.';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function buildLineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'list', 'min:1'],
            'lines.*' => ['array'],
            // distinct is loose, so 5 and "5" count as the same fee item
            'lines.*.fee_item_id' => [...StoreSectionRequest::buildIdRules('required', FeeItem::class), 'distinct'],
            'lines.*.amount' => ['bail', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::AMOUNT_MAX],
        ];
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
            'program_id' => StoreSectionRequest::buildIdRules('nullable', Program::class),
            ...self::buildLineRules(),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnbillableGradeLevel($validator),
            fn (Validator $validator) => $this->rejectUnfitProgram($validator),
            fn (Validator $validator) => $this->rejectTakenKey($validator),
            fn (Validator $validator) => $this->rejectInactiveFeeItems($validator),
        ];
    }

    public function buildKeyCandidate(): FeeSchedule
    {
        return new FeeSchedule($this->only(FeeSchedule::KEY_FIELDS));
    }

    protected function isLineUnchanged(array $line): bool
    {
        return false;
    }

    protected function rejectInactiveFeeItems(Validator $validator): void
    {
        $hasLineError = collect($validator->errors()->keys())->contains(fn (string $errorKey) => str_starts_with($errorKey, 'lines'));

        if ($hasLineError) {
            return;
        }

        $submittedLines = $this->input('lines');
        $inactiveIds = FeeItem::query()->whereIn('id', array_column($submittedLines, 'fee_item_id'))->where('is_active', false)->pluck('id');

        foreach ($submittedLines as $lineIndex => $submittedLine) {
            $isInactive = $inactiveIds->contains((int) $submittedLine['fee_item_id']);

            if ($isInactive && ! $this->isLineUnchanged($submittedLine)) {
                $validator->errors()->add('lines.'.$lineIndex.'.fee_item_id', self::FEE_ITEM_INACTIVE_MESSAGE);
            }
        }
    }

    private function rejectUnbillableGradeLevel(Validator $validator): void
    {
        if ($validator->errors()->has('grade_level_id')) {
            return;
        }

        if (in_array(GradeLevel::findOrFail($this->input('grade_level_id'))->stage, FeeSchedule::BILLABLE_STAGES, true)) {
            return;
        }

        $validator->errors()->add('grade_level_id', self::GRADE_LEVEL_NOT_BILLABLE_MESSAGE);
    }

    private function rejectUnfitProgram(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['grade_level_id', 'program_id'])) {
            return;
        }

        $programError = $this->findProgramError(GradeLevel::findOrFail($this->input('grade_level_id')), $this->findSubmittedProgram());

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
        return match (true) {
            $gradeLevel->stage === GradeLevel::STAGE_JHS && $program !== null => self::PROGRAM_NOT_ALLOWED_MESSAGE,
            $program === null => null,
            $program->stage !== $gradeLevel->stage => StoreSectionRequest::PROGRAM_STAGE_MESSAGE,
            ! $program->is_active => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE,
            default => null,
        };
    }

    private function rejectTakenKey(Validator $validator): void
    {
        if ($validator->errors()->hasAny(FeeSchedule::KEY_FIELDS)) {
            return;
        }

        if (! FeeSchedule::withSameKey($this->buildKeyCandidate())->exists()) {
            return;
        }

        $validator->errors()->add('grade_level_id', self::KEY_TAKEN_MESSAGE);
    }
}
