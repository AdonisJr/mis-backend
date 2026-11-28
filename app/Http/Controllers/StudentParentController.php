<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkParentRequest;
use App\Http\Resources\StudentParentResource;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentParentController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    public const ALREADY_LINKED_MESSAGE = 'This parent is already linked to this student.';

    public function listParents(int $id): JsonResponse
    {
        $student = Student::findOrFail($id);

        return response()->json(['parents' => StudentParentResource::collection($student->parents()->with('children')->get())]);
    }

    public function linkParent(LinkParentRequest $request, int $id): JsonResponse
    {
        $linkedParent = DB::transaction(function () use ($request, $id) {
            $student = Student::lockForUpdate()->findOrFail($id);
            $parent = User::parentAccounts()->where('mobile_number', $request->validated('mobile_number'))->lockForUpdate()->firstOrFail();

            if ($student->parents()->whereKey($parent->id)->exists()) {
                throw ValidationException::withMessages(['mobile_number' => self::ALREADY_LINKED_MESSAGE]);
            }

            $student->parents()->attach($parent->id, ['relationship' => $request->validated('relationship')]);

            return $student->parents()->with('children')->findOrFail($parent->id);
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['parent' => StudentParentResource::make($linkedParent)], Response::HTTP_CREATED);
    }

    public function unlinkParent(int $id, int $parentId): HttpResponse
    {
        DB::transaction(function () use ($id, $parentId) {
            $student = Student::lockForUpdate()->findOrFail($id);
            $student->parents()->findOrFail($parentId);
            $student->parents()->detach($parentId);
        }, self::TRANSACTION_ATTEMPTS);

        return response()->noContent();
    }
}
