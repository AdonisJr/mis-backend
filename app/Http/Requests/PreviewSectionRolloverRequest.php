<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewSectionRolloverRequest extends FormRequest
{
    public const JHS_ROLLOVER_MESSAGE = 'A junior high school section has no terms to roll over.';

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
            'term_id' => StoreSectionRequest::buildIdRules('required', Term::class),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnfitSourceTerm($validator),
        ];
    }

    protected function findRouteSection(): ?Section
    {
        return Section::with('gradeLevel')->find($this->route('id'));
    }

    private function rejectUnfitSourceTerm(Validator $validator): void
    {
        $section = $this->findRouteSection();

        if ($validator->errors()->has('term_id') || ! $section) {
            return;
        }

        $sourceTermError = $this->findSourceTermError($section, Term::findOrFail($this->input('term_id')));

        if ($sourceTermError === null) {
            return;
        }

        $validator->errors()->add('term_id', $sourceTermError);
    }

    private function findSourceTermError(Section $section, Term $sourceTerm): ?string
    {
        return match (true) {
            $section->gradeLevel->stage === GradeLevel::STAGE_JHS => self::JHS_ROLLOVER_MESSAGE,
            (int) $sourceTerm->school_year_id !== $section->school_year_id => StoreEnrollmentRequest::TERM_YEAR_MESSAGE,
            default => null,
        };
    }
}
