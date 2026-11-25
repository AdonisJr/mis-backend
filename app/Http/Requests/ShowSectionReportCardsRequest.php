<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RefusesWithMessage;
use App\Models\FeeSchedule;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Support\ClassListAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowSectionReportCardsRequest extends FormRequest
{
    use RefusesWithMessage;

    public const NOT_PERMITTED_MESSAGE = 'You can only view report cards of the section you advise.';

    public const STAGE_MESSAGE = 'Report cards are available for junior and senior high school sections only.';

    private ?Section $section = null;

    public function authorize(): bool
    {
        return (new ClassListAccess($this->user()))->canReadSectionReportCards($this->findSection());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnavailableCards($validator),
        ];
    }

    public function findSection(): Section
    {
        return $this->section ??= Section::with('schoolYear', 'gradeLevel')->findOrFail($this->route('id'));
    }

    protected function resolveNotPermittedMessage(): string
    {
        return self::NOT_PERMITTED_MESSAGE;
    }

    private function rejectUnavailableCards(Validator $validator): void
    {
        $section = $this->findSection();

        if ($section->schoolYear->term_type === SchoolYear::TERM_TYPE_TRIMESTER) {
            $validator->errors()->add('school_year_id', ShowClassQuarterRequest::TRIMESTER_MESSAGE);
        }

        if (! in_array($section->gradeLevel->stage, FeeSchedule::BILLABLE_STAGES, true)) {
            $validator->errors()->add('section_id', self::STAGE_MESSAGE);
        }
    }
}
