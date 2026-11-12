<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class StoreTermRequest extends FormRequest
{
    private const DATE_RULE = 'date_format:Y-m-d';

    public const OVERLAP_MESSAGE = 'The term overlaps the %s.';

    public const START_ORDER_MESSAGE = 'The term must start after the %s ends.';

    public const END_ORDER_MESSAGE = 'The term must end before the %s starts.';

    private ?SchoolYear $schoolYear = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function resolveSchoolYear(): SchoolYear
    {
        return $this->schoolYear ??= SchoolYear::findOrFail($this->route('id'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $schoolYear = $this->resolveSchoolYear();
        $firstDayRule = 'after_or_equal:'.$schoolYear->start_date->toDateString();
        $lastDayRule = 'before_or_equal:'.$schoolYear->end_date->toDateString();

        return [
            'term_number' => ['required', 'integer', 'between:1,'.SchoolYear::TERM_COUNTS[$schoolYear->term_type], $this->getUniqueTermNumberRule()],
            'start_date' => ['required', self::DATE_RULE, $firstDayRule, $lastDayRule],
            'end_date' => ['required', self::DATE_RULE, 'after:start_date', $firstDayRule, $lastDayRule],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectMisplacedTerm($validator)];
    }

    protected function getUniqueTermNumberRule(): Unique
    {
        return Rule::unique(Term::class, 'term_number')->where('school_year_id', $this->resolveSchoolYear()->id);
    }

    /** @return Collection<int, Term> */
    protected function getOtherTerms(): Collection
    {
        return $this->resolveSchoolYear()->terms()->get();
    }

    private function rejectMisplacedTerm(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['term_number', 'start_date', 'end_date'])) {
            return;
        }

        foreach ($this->getOtherTerms() as $otherTerm) {
            $this->compareWithOtherTerm($validator, $otherTerm);
        }
    }

    private function compareWithOtherTerm(Validator $validator, Term $otherTerm): void
    {
        $termNumber = (int) $this->input('term_number');
        $startDate = $this->input('start_date');
        $endDate = $this->input('end_date');
        $otherStart = $otherTerm->start_date->toDateString();
        $otherEnd = $otherTerm->end_date->toDateString();
        $isEarlierTerm = $otherTerm->term_number < $termNumber;
        $isInOrder = $isEarlierTerm ? $startDate > $otherEnd : $endDate < $otherStart;

        if ($isInOrder) {
            return;
        }

        $isOverlapping = $startDate <= $otherEnd && $otherStart <= $endDate;
        $errorKey = $isEarlierTerm ? 'start_date' : 'end_date';
        $messageFormat = match (true) {
            $isOverlapping => self::OVERLAP_MESSAGE,
            $isEarlierTerm => self::START_ORDER_MESSAGE,
            default => self::END_ORDER_MESSAGE,
        };

        $validator->errors()->add($errorKey, sprintf($messageFormat, Term::buildLabel($this->resolveSchoolYear()->term_type, $otherTerm->term_number)));
    }
}
