<?php

namespace App\Http\Requests;

use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateSchoolYearRequest extends StoreSchoolYearRequest
{
    public const TERM_TYPE_LOCKED_MESSAGE = 'The term type cannot be changed while the school year has terms.';

    public const START_AFTER_TERMS_MESSAGE = 'The start date cannot be after the start of an existing term.';

    public const END_BEFORE_TERMS_MESSAGE = 'The end date cannot be before the end of an existing term.';

    public const START_AFTER_PLAN_DUE_DATES_MESSAGE = 'The start date cannot be after the due date of an existing payment plan installment.';

    public const END_BEFORE_PLAN_DUE_DATES_MESSAGE = 'The end date cannot be before the due date of an existing payment plan installment.';

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        $schoolYear = SchoolYear::with('terms')->find($this->route('id'));

        if (! $schoolYear) {
            return [];
        }

        return [
            fn (Validator $validator) => $this->rejectTermTypeChange($validator, $schoolYear),
            fn (Validator $validator) => $this->rejectDatesOutsideTerms($validator, $schoolYear),
            fn (Validator $validator) => $this->rejectDatesOutsidePlanDueDates($validator, $schoolYear),
        ];
    }

    protected function getUniqueNameRule(): Unique
    {
        return parent::getUniqueNameRule()->ignore($this->route('id'));
    }

    private function rejectTermTypeChange(Validator $validator, SchoolYear $schoolYear): void
    {
        if ($validator->errors()->has('term_type') || $schoolYear->terms->isEmpty()) {
            return;
        }

        if ($this->input('term_type') === $schoolYear->term_type) {
            return;
        }

        $validator->errors()->add('term_type', self::TERM_TYPE_LOCKED_MESSAGE);
    }

    private function rejectDatesOutsideTerms(Validator $validator, SchoolYear $schoolYear): void
    {
        if ($validator->errors()->hasAny(['start_date', 'end_date']) || $schoolYear->terms->isEmpty()) {
            return;
        }

        // compared as Y-m-d strings: SQLite stores the date casts with a time part, so raw column values would not compare with the input
        $earliestTermStart = $schoolYear->terms->min(fn (Term $term) => $term->start_date->toDateString());
        $latestTermEnd = $schoolYear->terms->max(fn (Term $term) => $term->end_date->toDateString());

        if ($this->input('start_date') > $earliestTermStart) {
            $validator->errors()->add('start_date', self::START_AFTER_TERMS_MESSAGE);
        }

        if ($this->input('end_date') < $latestTermEnd) {
            $validator->errors()->add('end_date', self::END_BEFORE_TERMS_MESSAGE);
        }
    }

    private function rejectDatesOutsidePlanDueDates(Validator $validator, SchoolYear $schoolYear): void
    {
        if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
            return;
        }

        $dueDateBounds = PaymentPlanInstallment::findDueDateBounds($schoolYear->id);

        if ($dueDateBounds === null) {
            return;
        }

        if ($this->input('start_date') > $dueDateBounds['earliest']) {
            $validator->errors()->add('start_date', self::START_AFTER_PLAN_DUE_DATES_MESSAGE);
        }

        if ($this->input('end_date') < $dueDateBounds['latest']) {
            $validator->errors()->add('end_date', self::END_BEFORE_PLAN_DUE_DATES_MESSAGE);
        }
    }
}
