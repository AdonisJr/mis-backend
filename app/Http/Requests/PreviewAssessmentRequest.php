<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Support\AssessmentCalculator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewAssessmentRequest extends FormRequest
{
    protected const ID_KEYS = ['student_id', 'school_year_id', 'payment_plan_id'];

    private ?Assessment $assessmentCandidate = null;

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
            'payment_plan_id' => StoreSectionRequest::buildIdRules('required', PaymentPlan::class),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectIneligibleAssessment($validator),
        ];
    }

    public function buildAssessmentCandidate(): Assessment
    {
        if ($this->assessmentCandidate === null) {
            $this->assessmentCandidate = new Assessment([
                'student_id' => (int) $this->input('student_id'),
                'school_year_id' => (int) $this->input('school_year_id'),
                'payment_plan_id' => (int) $this->input('payment_plan_id'),
            ]);
            $this->assessmentCandidate->forceFill(['created_by' => $this->user()->id]);
        }

        return $this->assessmentCandidate;
    }

    private function rejectIneligibleAssessment(Validator $validator): void
    {
        if ($validator->errors()->hasAny(self::ID_KEYS)) {
            return;
        }

        $eligibilityErrors = (new AssessmentCalculator($this->buildAssessmentCandidate()))->findEligibilityErrors();

        foreach ($eligibilityErrors as $key => $message) {
            $validator->errors()->add($key, $message);
        }
    }
}
