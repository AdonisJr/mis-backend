<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RefusesWithMessage;
use App\Models\SchoolClass;
use App\Support\ClassListAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowClassQuarterRequest extends FormRequest
{
    use RefusesWithMessage;

    public const READ_NOT_PERMITTED_MESSAGE = 'You can only view class records of classes you teach or of the section you advise.';

    public const TRIMESTER_MESSAGE = 'Grading is not available for a trimester school year.';

    public const QUARTER_RANGE_MESSAGE = 'The quarter must be one of %s for this class.';

    private ?SchoolClass $schoolClass = null;

    // the lookup runs in authorize(), which runs before the rules, so a missing class is a 404 before any scope check
    public function authorize(): bool
    {
        return (new ClassListAccess($this->user()))->canReadClass($this->findSchoolClass());
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
            fn (Validator $validator) => $this->rejectUngradableQuarter($validator),
        ];
    }

    public function findSchoolClass(): SchoolClass
    {
        return $this->schoolClass ??= SchoolClass::with(SchoolClass::CLASS_LIST_RELATIONS)->findOrFail($this->route('id'));
    }

    public function resolveQuarter(): int
    {
        return (int) $this->route('quarter');
    }

    protected function resolveNotPermittedMessage(): string
    {
        return self::READ_NOT_PERMITTED_MESSAGE;
    }

    private function rejectUngradableQuarter(Validator $validator): void
    {
        $schoolClass = $this->findSchoolClass();

        if ($schoolClass->isInTrimesterYear()) {
            $validator->errors()->add('quarter', self::TRIMESTER_MESSAGE);

            return;
        }

        $gradableQuarters = $schoolClass->listGradableQuarters();

        if (! in_array($this->resolveQuarter(), $gradableQuarters, true)) {
            $validator->errors()->add('quarter', sprintf(self::QUARTER_RANGE_MESSAGE, implode(', ', $gradableQuarters)));
        }
    }
}
