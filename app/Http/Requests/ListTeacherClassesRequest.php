<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesClassListTeacher;
use App\Models\User;

class ListTeacherClassesRequest extends ListTimetableRequest
{
    use ResolvesClassListTeacher;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'teacher_id' => StoreSectionRequest::buildIdRules('nullable', User::class),
        ];
    }
}
