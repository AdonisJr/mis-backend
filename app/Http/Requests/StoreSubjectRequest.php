<?php

namespace App\Http\Requests;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubjectRequest extends FormRequest
{
    public const CODE_TAKEN_MESSAGE = 'The code has already been taken.';

    public const WEIGHT_GROUP_UNFIT_MESSAGE = 'The weight group does not fit the subject type.';

    private const CODE_MAX_LENGTH = 20;

    private const NAME_MAX_LENGTH = 150;

    private const DESCRIPTION_MAX_LENGTH = 500;

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
            'code' => ['required', 'string', 'max:'.self::CODE_MAX_LENGTH],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'type' => ['required', 'string', Rule::in(Subject::TYPES)],
            'weekly_hours' => ['bail', 'required', 'numeric', 'min:'.Subject::WEEKLY_HOURS_MIN, 'max:'.Subject::WEEKLY_HOURS_MAX, 'multiple_of:'.Subject::WEEKLY_HOURS_STEP],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'weight_group' => ['required', 'string', Rule::in(Subject::WEIGHT_GROUPS)],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTakenCode($validator),
            fn (Validator $validator) => $this->rejectUnfitWeightGroup($validator),
        ];
    }

    protected function buildSameCodeQuery(): Builder
    {
        // LOWER on both sides folds stored and submitted codes the same way on every database
        return Subject::query()->whereRaw('LOWER(code) = LOWER(?)', [$this->input('code')]);
    }

    private function rejectTakenCode(Validator $validator): void
    {
        if ($validator->errors()->has('code')) {
            return;
        }

        if (! $this->buildSameCodeQuery()->exists()) {
            return;
        }

        $validator->errors()->add('code', self::CODE_TAKEN_MESSAGE);
    }

    private function rejectUnfitWeightGroup(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['type', 'weight_group'])) {
            return;
        }

        if (! in_array($this->input('weight_group'), Subject::TYPE_WEIGHT_GROUPS[$this->input('type')], true)) {
            $validator->errors()->add('weight_group', self::WEIGHT_GROUP_UNFIT_MESSAGE);
        }
    }
}
