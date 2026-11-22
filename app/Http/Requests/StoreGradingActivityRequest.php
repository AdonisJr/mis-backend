<?php

namespace App\Http\Requests;

use App\Models\GradingActivity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGradingActivityRequest extends ChangeClassQuarterRequest
{
    public const SECOND_QA_MESSAGE = 'A quarter can have only one QA activity.';

    public const HIGHEST_SCORE_MAX = 999;

    private const TITLE_MAX_LENGTH = 150;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'component' => ['required', 'string', Rule::in(GradingActivity::COMPONENTS)],
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX_LENGTH],
            'highest_possible_score' => ['bail', 'required', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'min:1', 'max:'.self::HIGHEST_SCORE_MAX],
            'activity_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectSecondQa($validator),
        ];
    }

    private function rejectSecondQa(Validator $validator): void
    {
        if ($validator->errors()->has('component') || $this->input('component') !== GradingActivity::COMPONENT_QA) {
            return;
        }

        if ($this->findSchoolClass()->hasQaActivity($this->resolveQuarter(), $this->findActivity()?->id)) {
            $validator->errors()->add('component', self::SECOND_QA_MESSAGE);
        }
    }
}
