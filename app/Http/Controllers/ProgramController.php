<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProgramController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['code', 'name'];

    public const SECTIONS_DELETE_MESSAGE = 'The program has sections and cannot be deleted.';

    public const FEE_SCHEDULES_DELETE_MESSAGE = 'The program has fee schedules and cannot be deleted.';

    public function listPrograms(ListProgramsRequest $request): JsonResponse
    {
        $programQuery = Program::query()->orderBy('code');
        $this->applySearch($programQuery, (string) $request->validated('search'));
        $programQuery->when($request->filled('stage'), fn (Builder $query) => $query->where('stage', $request->validated('stage')));
        $programQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $programPage = $programQuery->paginate();

        return response()->json([
            'programs' => ProgramResource::collection($programPage->getCollection()),
            'meta' => $this->buildPageMeta($programPage),
        ]);
    }

    public function createProgram(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create($request->validated());

        return response()->json([
            'program' => ProgramResource::make($program),
        ], Response::HTTP_CREATED);
    }

    public function updateProgram(UpdateProgramRequest $request, int $id): JsonResponse
    {
        $program = Program::findOrFail($id);
        $program->update($request->validated());

        return response()->json([
            'program' => ProgramResource::make($program),
        ]);
    }

    public function deactivateProgram(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivateProgram(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    public function deleteProgram(int $id): Response
    {
        $program = Program::findOrFail($id);

        if ($program->sections()->exists()) {
            return response()->json(['message' => self::SECTIONS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($program->feeSchedules()->exists()) {
            return response()->json(['message' => self::FEE_SCHEDULES_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $program->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $program = Program::findOrFail($id);
        $program->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'program' => ProgramResource::make($program),
        ]);
    }
}
