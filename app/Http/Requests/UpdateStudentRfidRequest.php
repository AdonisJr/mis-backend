<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesRfid;
use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStudentRfidRequest extends FormRequest
{
    use NormalisesRfid;

    public const CARD_TAKEN_MESSAGE = 'This card is already assigned to %s (LRN %s).';

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
        return ['rfid' => $this->buildRfidRules()];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectCardOfAnotherStudent($validator)];
    }

    public static function buildCardTakenMessage(Student $cardHolder): string
    {
        return sprintf(self::CARD_TAKEN_MESSAGE, $cardHolder->buildFullName(), $cardHolder->lrn);
    }

    private function rejectCardOfAnotherStudent(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $cardHolder = Student::where('rfid', $this->input('rfid'))->whereKeyNot($this->route('id'))->first();

        if ($cardHolder === null) {
            return;
        }

        $validator->errors()->add('rfid', self::buildCardTakenMessage($cardHolder));
    }
}
