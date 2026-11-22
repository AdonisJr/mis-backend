<?php

namespace App\Http\Requests\Concerns;

use App\Support\ClassListAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesClassListTeacher
{
    public const FOREIGN_TEACHER_MESSAGE = 'You can only view your own class lists.';

    public function authorize(): bool
    {
        if (! $this->filled('teacher_id')) {
            return true;
        }

        $isOwnId = is_scalar($this->input('teacher_id')) && (string) $this->input('teacher_id') === (string) $this->user()->id;

        return $isOwnId || (new ClassListAccess($this->user()))->isRegistryCaller();
    }

    public function resolveTeacherId(): int
    {
        return (int) ($this->validated('teacher_id') ?? $this->user()->id);
    }

    // authorize runs before the rules, so another teacher's id is refused before any lookup reveals their data; built as a response because a thrown AuthorizationException renders debug traces under APP_DEBUG
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json(['message' => self::FOREIGN_TEACHER_MESSAGE], Response::HTTP_FORBIDDEN));
    }
}
