<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEnrollmentRequest extends FormRequest
{
    public const DATE_RULES = ['nullable', 'date_format:Y-m-d'];

    public const REMARKS_RULES = ['nullable', 'string', 'max:1000'];

    public const TERM_NOT_ALLOWED_MESSAGE = 'A junior high school enrollment cannot have a term.';

    public const TERM_REQUIRED_MESSAGE = 'The term field is required for this section.';

    public const TERM_YEAR_MESSAGE = "The term must belong to the section's school year.";

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
            'student_id' => StoreSectionRequest::buildIdRules('required', Student::class),
            'section_id' => StoreSectionRequest::buildIdRules('required', Section::class),
            'term_id' => StoreSectionRequest::buildIdRules('nullable', Term::class),
            'enrolled_at' => self::DATE_RULES,
            'remarks' => self::REMARKS_RULES,
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnfitTerm($validator),
        ];
    }

    private function rejectUnfitTerm(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['section_id', 'term_id'])) {
            return;
        }

        $submittedTerm = $this->filled('term_id') ? Term::findOrFail($this->input('term_id')) : null;
        $termError = self::findTermError(Section::with('gradeLevel')->findOrFail($this->input('section_id')), $submittedTerm);

        if ($termError === null) {
            return;
        }

        $validator->errors()->add('term_id', $termError);
    }

    public static function findTermError(Section $section, ?Term $submittedTerm): ?string
    {
        $isJuniorHigh = $section->gradeLevel->stage === GradeLevel::STAGE_JHS;

        return match (true) {
            $isJuniorHigh && $submittedTerm !== null => self::TERM_NOT_ALLOWED_MESSAGE,
            $isJuniorHigh => null,
            $submittedTerm === null => self::TERM_REQUIRED_MESSAGE,
            (int) $submittedTerm->school_year_id !== $section->school_year_id => self::TERM_YEAR_MESSAGE,
            default => null,
        };
    }
}
