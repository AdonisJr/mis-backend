<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoomRequest extends FormRequest
{
    public const NAME_TAKEN_MESSAGE = 'The name has already been taken.';

    private const NAME_MAX_LENGTH = 50;

    private const BUILDING_MAX_LENGTH = 100;

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
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'building' => ['nullable', 'string', 'max:'.self::BUILDING_MAX_LENGTH],
            'capacity' => ['bail', 'nullable', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'min:1', 'max:'.StoreSectionRequest::CAPACITY_MAX],
            'type' => ['required', 'string', Rule::in(Room::TYPES)],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectTakenName($validator),
        ];
    }

    protected function buildSameNameQuery(): Builder
    {
        // LOWER on both sides folds stored and submitted names the same way on every database
        return Room::query()->whereRaw('LOWER(name) = LOWER(?)', [$this->input('name')]);
    }

    private function rejectTakenName(Validator $validator): void
    {
        if ($validator->errors()->has('name')) {
            return;
        }

        if (! $this->buildSameNameQuery()->exists()) {
            return;
        }

        $validator->errors()->add('name', self::NAME_TAKEN_MESSAGE);
    }
}
