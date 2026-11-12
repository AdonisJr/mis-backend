<?php

namespace App\Http\Requests;

use App\Models\Section;

class MoveEnrollmentRequest extends CloseEnrollmentRequest
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
            'section_id' => StoreSectionRequest::buildIdRules('required', Section::class),
        ];
    }
}
