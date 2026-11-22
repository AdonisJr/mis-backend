<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

trait RefusesWithMessage
{
    abstract protected function resolveNotPermittedMessage(): string;

    // built as a response because a thrown AuthorizationException renders debug traces under APP_DEBUG
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json(['message' => $this->resolveNotPermittedMessage()], Response::HTTP_FORBIDDEN));
    }
}
