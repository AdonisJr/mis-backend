<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSectionRequest extends FormRequest
{
    // digit-only: the plain integer rule alone would accept true as 1
    public const WHOLE_NUMBER_RULE = 'regex:/\A[0-9]+\z/';

    // largest value the capacity column holds on PostgreSQL (unsignedInteger maps to a signed integer there)
    public const CAPACITY_MAX = 2147483647;

    public const PROGRAM_NOT_ALLOWED_MESSAGE = 'A junior high school section cannot have a program.';

    public const PROGRAM_REQUIRED_MESSAGE = 'The program field is required for this grade level.';

    public const PROGRAM_STAGE_MESSAGE = 'The program must belong to the same stage as the grade level.';

    public const PROGRAM_INACTIVE_MESSAGE = 'The selected program is inactive.';

    public const NAME_TAKEN_MESSAGE = 'The name has already been taken for this school year, grade level and program.';

    private const NAME_RULES = ['required', 'string', 'max:255'];

    private const ROOM_RULES = ['nullable', 'string', 'max:255'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public static function buildIdRules(string $presenceRule, string $modelClass): array
    {
        // bail keeps exists from querying with a malformed id, which PostgreSQL would reject as a type error
        return ['bail', $presenceRule, 'integer', self::WHOLE_NUMBER_RULE, Rule::exists($modelClass, 'id')];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_year_id' => self::buildIdRules('required', SchoolYear::class),
            'grade_level_id' => self::buildIdRules('required', GradeLevel::class),
            'program_id' => self::buildIdRules('nullable', Program::class),
            'name' => self::NAME_RULES,
            'capacity' => ['bail', 'required', 'integer', self::WHOLE_NUMBER_RULE, 'min:1', 'max:'.self::CAPACITY_MAX],
            'room' => self::ROOM_RULES,
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnfitProgram($validator),
            fn (Validator $validator) => $this->rejectTakenName($validator),
        ];
    }

    protected function isProgramChanged(Program $program): bool
    {
        return true;
    }

    protected function buildSameGroupQuery(): Builder
    {
        // a null program_id compiles to IS NULL, so all program-less sections of a grade level form one group
        return Section::query()
            ->where('school_year_id', $this->input('school_year_id'))
            ->where('grade_level_id', $this->input('grade_level_id'))
            ->where('program_id', $this->input('program_id'));
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
        $isJuniorHigh = $gradeLevel->stage === GradeLevel::STAGE_JHS;

        return match (true) {
            $isJuniorHigh && $program !== null => self::PROGRAM_NOT_ALLOWED_MESSAGE,
            $isJuniorHigh => null,
            $program === null => self::PROGRAM_REQUIRED_MESSAGE,
            $program->stage !== $gradeLevel->stage => self::PROGRAM_STAGE_MESSAGE,
            ! $program->is_active && $this->isProgramChanged($program) => self::PROGRAM_INACTIVE_MESSAGE,
            default => null,
        };
    }

    private function rejectTakenName(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['school_year_id', 'grade_level_id', 'program_id', 'name'])) {
            return;
        }

        // LOWER on both sides folds stored and submitted names the same way on every database
        $isNameTaken = $this->buildSameGroupQuery()->whereRaw('LOWER(name) = LOWER(?)', [$this->input('name')])->exists();

        if (! $isNameTaken) {
            return;
        }

        $validator->errors()->add('name', self::NAME_TAKEN_MESSAGE);
    }
}
