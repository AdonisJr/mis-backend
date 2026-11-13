<?php

namespace App\Http\Requests;

class RolloverSectionRequest extends PreviewSectionRolloverRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'enrollment_ids' => ['required', 'array', 'list', 'min:1'],
            'enrollment_ids.*' => ['bail', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE, 'distinct'],
            'ended_at' => StoreEnrollmentRequest::DATE_RULES,
            'remarks' => StoreEnrollmentRequest::REMARKS_RULES,
        ];
    }
}
