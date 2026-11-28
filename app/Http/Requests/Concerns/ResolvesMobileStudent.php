<?php

namespace App\Http\Requests\Concerns;

use App\Models\Role;
use App\Models\Student;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesMobileStudent
{
    public const UNLINKED_CHILD_MESSAGE = 'This student is not linked to your account.';

    private ?Student $student = null;

    public function authorize(): bool
    {
        if (! $this->isParentRequest()) {
            return true;
        }

        return $this->user()->children()->whereKey($this->resolveStudentId())->exists();
    }

    // 404 when the student row was removed and user_id was nulled
    public function findStudent(): Student
    {
        if ($this->isParentRequest()) {
            return $this->student ??= $this->user()->children()->findOrFail($this->resolveStudentId());
        }

        return $this->student ??= $this->user()->student()->firstOrFail();
    }

    // authorize runs before the rules, so an unlinked id is refused before any 422; built as a response because a thrown AuthorizationException renders debug traces under APP_DEBUG
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json(['message' => self::UNLINKED_CHILD_MESSAGE], Response::HTTP_FORBIDDEN));
    }

    private function isParentRequest(): bool
    {
        return $this->user()->hasRole(Role::PARENT);
    }

    private function resolveStudentId(): int
    {
        $studentIdInput = $this->input('student_id');

        // ctype_digit keeps "1abc" from casting to 1
        return is_string($studentIdInput) && ctype_digit($studentIdInput) ? (int) $studentIdInput : 0;
    }
}
