<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowSectionTimetableRequest extends FormRequest
{
    public const TERM_NOT_ALLOWED_MESSAGE = 'The term number is only for senior high school sections.';

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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'term_number' => ['bail', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE],
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
        // a missing id returns null here and the controller answers 404
        $section = Section::with('gradeLevel', 'schoolYear')->find($this->route('id'));

        if (! $section || $validator->errors()->has('term_number')) {
            return;
        }

        $termError = $this->findTermError($section);

        if ($termError === null) {
            return;
        }

        $validator->errors()->add('term_number', $termError);
    }

    private function findTermError(Section $section): ?string
    {
        $hasTerm = $this->filled('term_number');
        $termCount = SchoolYear::TERM_COUNTS[$section->schoolYear->term_type];

        return match (true) {
            $section->gradeLevel->stage !== GradeLevel::STAGE_SHS && $hasTerm => self::TERM_NOT_ALLOWED_MESSAGE,
            $section->gradeLevel->stage !== GradeLevel::STAGE_SHS => null,
            ! $hasTerm => StoreCurriculumEntryRequest::TERM_REQUIRED_MESSAGE,
            (int) $this->input('term_number') < 1 || (int) $this->input('term_number') > $termCount => sprintf(StoreCurriculumEntryRequest::TERM_RANGE_MESSAGE, $termCount),
            default => null,
        };
    }
}
