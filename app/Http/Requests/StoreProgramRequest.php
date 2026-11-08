<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreProgramRequest extends FormRequest
{
    private const CODE_MAX_LENGTH = 20;

    private const NAME_RULES = ['required', 'string', 'max:255'];

    private const DESCRIPTION_RULES = ['nullable', 'string', 'max:1000'];

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
            'code' => ['required', 'string', 'max:'.self::CODE_MAX_LENGTH, $this->getUniqueCodeRule()],
            'name' => self::NAME_RULES,
            'stage' => ['required', 'string', Rule::in(GradeLevel::PROGRAM_STAGES)],
            'description' => self::DESCRIPTION_RULES,
        ];
    }

    protected function prepareForValidation(): void
    {
        $programCode = $this->input('code');

        if (! is_string($programCode)) {
            return;
        }

        // stored upper-case, so the plain unique index and Rule::unique ignore case in effect
        $this->merge(['code' => mb_strtoupper(trim($programCode))]);
    }

    protected function getUniqueCodeRule(): Unique
    {
        return Rule::unique(Program::class, 'code');
    }
}
