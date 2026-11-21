<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\ListSubjectsRequest;
use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SubjectController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['code', 'name'];

    public function listSubjects(ListSubjectsRequest $request): JsonResponse
    {
        $subjectQuery = Subject::query()->orderBy('code')->orderBy('id');
        $this->applySearch($subjectQuery, (string) $request->validated('search'));
        $subjectQuery->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->validated('type')));
        $subjectQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $subjectPage = $subjectQuery->paginate();

        return response()->json([
            'subjects' => SubjectResource::collection($subjectPage->getCollection()),
            'meta' => $this->buildPageMeta($subjectPage),
        ]);
    }

    public function listActiveSubjects(): JsonResponse
    {
        $activeSubjects = Subject::active()->orderBy('code')->orderBy('id')->get();

        return response()->json([
            'subjects' => SubjectResource::collection($activeSubjects),
        ]);
    }

    public function createSubject(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Subject::create($request->validated());

        return response()->json([
            'subject' => SubjectResource::make($subject),
        ], Response::HTTP_CREATED);
    }

    public function updateSubject(UpdateSubjectRequest $request, int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $subject->update($request->validated());

        return response()->json([
            'subject' => SubjectResource::make($subject),
        ]);
    }

    public function deactivateSubject(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivateSubject(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $subject->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'subject' => SubjectResource::make($subject),
        ]);
    }
}
