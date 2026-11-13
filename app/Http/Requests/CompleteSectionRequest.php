<?php

namespace App\Http\Requests;

use App\Models\Section;
use App\Models\Term;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompleteSectionRequest extends FormRequest
{
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
            'term_id' => StoreSectionRequest::buildIdRules('nullable', Term::class),
            'ended_at' => StoreEnrollmentRequest::DATE_RULES,
            'remarks' => StoreEnrollmentRequest::REMARKS_RULES,
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

    private function findRouteSection(): ?Section
    {
        return Section::with('gradeLevel')->find($this->route('id'));
    }

    private function rejectUnfitTerm(Validator $validator): void
    {
        $section = $this->findRouteSection();

        if ($validator->errors()->has('term_id') || ! $section) {
            return;
        }

        $submittedTerm = $this->filled('term_id') ? Term::findOrFail($this->input('term_id')) : null;
        $termError = StoreEnrollmentRequest::findTermError($section, $submittedTerm);

        if ($termError === null) {
            return;
        }

        $validator->errors()->add('term_id', $termError);
    }
}
