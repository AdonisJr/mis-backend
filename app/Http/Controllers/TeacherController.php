<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListTeachersRequest;
use App\Http\Resources\TeacherResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class TeacherController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['name'];

    public function listTeachers(ListTeachersRequest $request): JsonResponse
    {
        $teacherQuery = $this->buildOrderedTeacherQuery();
        $this->applySearch($teacherQuery, (string) $request->validated('search'));
        $teacherPage = $teacherQuery->paginate();

        return response()->json([
            'teachers' => TeacherResource::collection($teacherPage->getCollection()),
            'meta' => $this->buildPageMeta($teacherPage),
        ]);
    }

    public function listActiveTeachers(): JsonResponse
    {
        $activeTeachers = $this->buildOrderedTeacherQuery()->get();

        return response()->json([
            'teachers' => TeacherResource::collection($activeTeachers),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildOrderedTeacherQuery(): Builder
    {
        return User::activeTeachers()->orderBy('name')->orderBy('id');
    }
}
