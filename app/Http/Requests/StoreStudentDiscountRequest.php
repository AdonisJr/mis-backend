<?php

namespace App\Http\Requests;

use App\Models\DiscountType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStudentDiscountRequest extends FormRequest
{
    public const NOT_ENROLLED_MESSAGE = 'The student has no enrolled or completed enrollment in this school year.';

    public const TYPE_INACTIVE_MESSAGE = 'The selected discount type is inactive.';

    public const STAGE_NOT_ALLOWED_MESSAGE = 'The discount type does not include the stage of this student.';

    public const TYPE_ALREADY_HELD_MESSAGE = 'The student already holds this discount type for this school year.';

    public const GOVERNMENT_LIMIT_MESSAGE = 'The student already holds a government discount type for this school year.';

    private const ID_KEYS = ['student_id', 'school_year_id', 'discount_type_id'];

    private ?StudentDiscount $assignmentCandidate = null;

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
            'student_id' => StoreSectionRequest::buildIdRules('required', Student::class),
            'school_year_id' => StoreSectionRequest::buildIdRules('required', SchoolYear::class),
            'discount_type_id' => StoreSectionRequest::buildIdRules('required', DiscountType::class),
            'reference_no' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectIneligibleAssignment($validator),
        ];
    }

    public function buildAssignmentCandidate(): StudentDiscount
    {
        $this->assignmentCandidate ??= new StudentDiscount([
            'student_id' => (int) $this->input('student_id'),
            'school_year_id' => (int) $this->input('school_year_id'),
            'discount_type_id' => (int) $this->input('discount_type_id'),
        ]);

        return $this->assignmentCandidate;
    }

    private function rejectIneligibleAssignment(Validator $validator): void
    {
        if ($validator->errors()->hasAny(self::ID_KEYS)) {
            return;
        }

        $enrolledStages = $this->buildAssignmentCandidate()->findEnrolledStages();

        if ($enrolledStages === []) {
            $validator->errors()->add('student_id', self::NOT_ENROLLED_MESSAGE);
        }

        $typeError = $this->findTypeError($enrolledStages);

        if ($typeError !== null) {
            $validator->errors()->add('discount_type_id', $typeError);
        }
    }

    /** @param list<string> $enrolledStages */
    private function findTypeError(array $enrolledStages): ?string
    {
        $candidate = $this->buildAssignmentCandidate();
        $discountType = $candidate->discountType;

        return match (true) {
            ! $discountType->is_active => self::TYPE_INACTIVE_MESSAGE,
            $enrolledStages !== [] && array_intersect($enrolledStages, $discountType->stages) === [] => self::STAGE_NOT_ALLOWED_MESSAGE,
            $candidate->isTypeAlreadyHeld() => self::TYPE_ALREADY_HELD_MESSAGE,
            $candidate->hasOtherGovernmentType() => self::GOVERNMENT_LIMIT_MESSAGE,
            default => null,
        };
    }
}
